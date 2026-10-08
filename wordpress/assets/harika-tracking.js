(() => {
  'use strict';

  const config = window.CCFGoogleAdsConfig;
  if (!config || !config.restUrl || !config.bootstrapUrl) return;

  let consent = {
    analytics: false,
    ads: false,
    source: 'unconfigured'
  };

  let pageAuth = null;
  let pageAuthPromise = null;

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return `${Date.now()}_${Math.random().toString(16).slice(2)}_${Math.random().toString(16).slice(2)}`;
  }

  async function getPageAuth() {
    const now = Math.floor(Date.now() / 1000);
    const expiresIn = Number(pageAuth?.expires_in || 300);
    const issued = Number(pageAuth?.issued || 0);
    if (pageAuth?.signature && issued > 0 && now - issued < Math.max(30, expiresIn - 30)) {
      return pageAuth;
    }

    if (pageAuthPromise) return pageAuthPromise;

    pageAuthPromise = fetch(config.bootstrapUrl, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    }).then(async (response) => {
      if (!response.ok) {
        throw new Error(`Connector bootstrap failed with HTTP ${response.status}`);
      }
      const body = await response.json();
      if (!body?.issued || !body?.signature) {
        throw new Error('Connector bootstrap returned an invalid response.');
      }
      pageAuth = body;
      return pageAuth;
    }).finally(() => {
      pageAuthPromise = null;
    });

    return pageAuthPromise;
  }

  function attribution() {
    if (!consent.ads) return undefined;
    const params = new URLSearchParams(window.location.search);
    const result = {};
    ['gclid', 'gbraid', 'wbraid'].forEach((key) => {
      const value = params.get(key);
      if (value) result[key] = value.slice(0, 256);
    });
    return Object.keys(result).length ? result : undefined;
  }

  function privacySafeUrl(value) {
    if (!value) return undefined;
    try {
      const url = new URL(value, window.location.origin);
      url.search = '';
      url.hash = '';
      return url.toString();
    } catch (_) {
      return undefined;
    }
  }

  function context() {
    const result = {
      page_url: privacySafeUrl(window.location.href),
      page_title: document.title || undefined,
      referrer: privacySafeUrl(document.referrer)
    };
    Object.keys(result).forEach((key) => result[key] === undefined && delete result[key]);
    return result;
  }

  function analyticsEventName(eventType, conversion) {
    const requested = typeof conversion?.action_key === 'string'
      ? conversion.action_key.trim().toLowerCase()
      : '';
    const fallback = String(eventType || 'conversion').trim().toLowerCase();
    const normalized = (requested || fallback)
      .replace(/[^a-z0-9_]/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_+|_+$/g, '')
      .slice(0, 40);
    return /^[a-z][a-z0-9_]{0,39}$/.test(normalized)
      ? normalized
      : 'ccf_conversion';
  }

  function mirrorToGoogleAnalytics(eventType, conversion) {
    if (!consent.analytics || typeof window.gtag !== 'function') return;
    window.gtag('event', analyticsEventName(eventType, conversion), {
      ccf_event_type: eventType,
      transport_type: 'beacon'
    });
  }

  async function track(eventType, options = {}) {
    if (!consent.ads) {
      return { status: 'skipped', reason: 'consent_not_granted' };
    }

    const auth = await getPageAuth();
    const payload = {
      event_id: options.eventId || `evt_${uuid()}`,
      event_type: eventType,
      occurred_at: options.occurredAt || new Date().toISOString(),
      consent: { ...consent },
      context: context()
    };

    const attr = attribution();
    if (attr) payload.attribution = attr;
    if (options.conversion) payload.conversion = options.conversion;
    if (options.metadata) payload.metadata = options.metadata;

    mirrorToGoogleAnalytics(eventType, options.conversion);

    let lastError = null;
    for (let attempt = 0; attempt < 3; attempt += 1) {
      try {
        const response = await fetch(config.restUrl, {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: true,
          headers: {
            'Content-Type': 'application/json',
            'X-CCF-WP-Issued': auth.issued,
            'X-CCF-WP-Signature': auth.signature
          },
          body: JSON.stringify(payload)
        });
        let body = null;
        try {
          body = await response.json();
        } catch (_) {
          body = null;
        }
        if (response.ok) return body;
        const error = new Error(body?.message || body?.error?.message || `Tracking request failed with HTTP ${response.status}`);
        error.status = response.status;
        error.code = body?.code || body?.error?.code || 'TRACKING_FAILED';
        if (response.status < 500 || attempt === 2) throw error;
        lastError = error;
      } catch (error) {
        lastError = error;
        if (attempt === 2 || (error?.status && error.status < 500)) throw error;
      }
      await new Promise((resolve) => window.setTimeout(resolve, 200 * (2 ** attempt)));
    }
    throw lastError || new Error('Tracking failed.');
  }

  function classifyLink(anchor) {
    const href = anchor.getAttribute('href') || '';
    if (href.startsWith('tel:')) return { eventType: 'phone_click', actionKey: 'phone_click' };
    if (href.startsWith('mailto:')) return { eventType: 'email_click', actionKey: 'email_click' };

    try {
      const url = new URL(href, window.location.href);
      const host = url.hostname.toLowerCase();
      if (host === 'wa.me' || host === 'api.whatsapp.com' || host.endsWith('.whatsapp.com')) {
        return { eventType: 'whatsapp_click', actionKey: 'whatsapp_click' };
      }
    } catch (_) {
      return null;
    }
    return null;
  }

  document.addEventListener('click', (event) => {
    const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!anchor) return;
    const classification = classifyLink(anchor);
    if (!classification) return;

    const actionKey = anchor.getAttribute('data-ccf-action-key') || classification.actionKey;
    void track(classification.eventType, {
      conversion: { action_key: actionKey }
    }).catch(() => {});
  }, { capture: true });

  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || form.getAttribute('data-ccf-google-ads-event') !== 'form_submit') return;

    const metadata = {};
    if (form.id) metadata.form_id = form.id.slice(0, 256);
    const actionKey = form.getAttribute('data-ccf-action-key') || 'contact_form';

    void track('form_submit', {
      conversion: { action_key: actionKey },
      metadata
    }).catch(() => {});
  }, { capture: true });

  window.CCFGoogleAds = Object.freeze({
    setConsent(next) {
      consent = {
        analytics: next?.analytics === true,
        ads: next?.ads === true,
        source: typeof next?.source === 'string' && next.source ? next.source.slice(0, 64) : 'site-consent-manager'
      };
      if (consent.ads) void getPageAuth().catch(() => {});
      return { ...consent };
    },
    getConsent() {
      return { ...consent };
    },
    track
  });

  function syncYooThemeConsent(manager) {
    if (!manager || typeof manager.hasConsent !== 'function') return;
    window.CCFGoogleAds.setConsent({
      analytics: manager.hasConsent('statistics.google_analytics') === true,
      ads: manager.hasConsent('marketing.google_ads') === true,
      source: 'yootheme-consent'
    });
  }

  document.addEventListener('yootheme:consent.init', (event) => {
    syncYooThemeConsent(event.detail);
  });

  document.addEventListener('yootheme:consent.change', (event) => {
    syncYooThemeConsent(event.detail);
  });

  syncYooThemeConsent(window.yootheme?.consent);
})();
