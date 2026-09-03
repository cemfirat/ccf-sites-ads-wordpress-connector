import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

test('WordPress bridge synchronizes YOOtheme consent', async () => {
  const listeners = new Map();
  const analyticsCalls = [];
  const script = await readFile(new URL('../wordpress/assets/ccf-tracking.js', import.meta.url), 'utf8');

  const document = {
    title: 'Example Customer',
    referrer: '',
    addEventListener(name, callback) {
      listeners.set(name, callback);
    }
  };

  const window = {
    CCFGoogleAdsConfig: {
      restUrl: 'https://customer.example/wp-json/ccf-google-ads/v1/event',
      bootstrapUrl: 'https://customer.example/wp-json/ccf-google-ads/v1/bootstrap'
    },
    crypto: { randomUUID: () => '00000000-0000-4000-8000-000000000000' },
    location: { href: 'https://customer.example/', origin: 'https://customer.example', search: '' },
    yootheme: {
      consent: { hasConsent: () => false }
    },
    gtag: (...args) => analyticsCalls.push(args)
  };

  const context = vm.createContext({
    window,
    document,
    Element: class Element {},
    HTMLFormElement: class HTMLFormElement {},
    URL,
    URLSearchParams,
    fetch: async () => ({
      ok: true,
      json: async () => ({ issued: '1', signature: 'test', expires_in: 300 })
    })
  });

  vm.runInContext(script, context);
  assert.deepEqual(
    JSON.parse(JSON.stringify(window.CCFGoogleAds.getConsent())),
    { analytics: false, ads: false, source: 'yootheme-consent' }
  );

  const change = listeners.get('yootheme:consent.change');
  assert.equal(typeof change, 'function');
  change({
    detail: {
      hasConsent(category) {
        return category === 'marketing.google_ads';
      }
    }
  });

  assert.deepEqual(
    JSON.parse(JSON.stringify(window.CCFGoogleAds.getConsent())),
    { analytics: false, ads: true, source: 'yootheme-consent' }
  );

  window.CCFGoogleAds.setConsent({
    analytics: true,
    ads: true,
    source: 'test-consent'
  });
  await window.CCFGoogleAds.track('form_submit', {
    conversion: { action_key: 'contact_form' }
  });
  assert.deepEqual(JSON.parse(JSON.stringify(analyticsCalls)), [[
    'event',
    'contact_form',
    { ccf_event_type: 'form_submit', transport_type: 'beacon' }
  ]]);
});
