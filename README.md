# CCF Sites & Ads WordPress Connector

Öffentlicher, geprüfter Release-Kanal für den universellen WordPress-Connector von CCF Sites & Ads.

Der gleiche Connector wird auf jeder verwalteten WordPress-Website installiert. Kunden, Websites sowie Google-Ads-, GA4- und Search-Console-Zuordnungen werden zentral in CCF Sites & Ads getrennt verwaltet. Google-Zugangsdaten liegen niemals im WordPress-Plugin.

## Funktionsumfang

- WordPress-, Theme- und Plugin-Inventar lesen
- Seiten, Beiträge und öffentliche Post-Types lesen
- bestehende Inhalte, Rank Math und YOOtheme über Preview/Freigabe/Verifikation/Rollback ändern
- neue Inhalte draft-first als `draft`, `pending` oder `private` anlegen
- öffentliche Taxonomien, Kategorien, Schlagwörter und Begriffe verwalten
- ACF-Felder auf unveröffentlichten Inhalten lesen und aktualisieren
- Bildmedien lesen und aus geschützten öffentlichen HTTPS-Quellen importieren
- Beitragsbilder auf unveröffentlichten Inhalten setzen
- unveröffentlichte Inhalte sicher in den Papierkorb verschieben
- consent-basiertes Conversion-Tracking für freigegebene Telefon-, E-Mail-, WhatsApp- und Formularsignale

Direktes Publizieren oder das unkontrollierte Verändern veröffentlichter Inhalte ist bewusst nicht Teil der Draft-First-Schnittstelle. Veröffentlichte Änderungen bleiben im zentralen CCF-Freigabepfad.

## Installation

1. [Aktuelles Plugin-ZIP herunterladen](https://github.com/cemfirat/ccf-sites-ads-wordpress-connector/releases/latest/download/ccf-sites-ads-connector.zip).
2. In WordPress unter **Plugins → Installieren → Plugin hochladen** auswählen.
3. Aktivieren und unter **Einstellungen → CCF Sites & Ads** mit dem zugehörigen Kunden-Workspace verbinden.
4. Im zentralen CCF-Workspace die passende Website und – unabhängig davon – Google Ads, GA4 und Search Console zuordnen.

Das ZIP enthält keine Zugangsdaten. Updates werden nach der ersten Installation über die öffentlichen GitHub-Releases in WordPress angeboten.

## Sicherheit

Die WordPress-Steuerung verwendet signierte Server-zu-Server-Anfragen. Der Website-Token bleibt serverseitig. Medienimporte akzeptieren nur öffentliche HTTPS-Bildquellen, begrenzen Dateigröße und MIME-Typ und blockieren private bzw. reservierte Netzwerkziele.

Bitte keine Tokens oder andere Zugangsdaten in Issues veröffentlichen. Sicherheitsmeldungen gehören an die im [Sicherheitshinweis](SECURITY.md) genannte Kontaktadresse.

## Entwicklungs- und Release-Workflow

`main` ist der geschützte Release-Branch. Änderungen werden über kurzlebige Arbeitsbranches und Pull Requests eingebracht. Der GitHub-Actions-Check `check` muss erfolgreich sein, bevor nach `main` gemergt wird. Force-Pushes und das Löschen von `main` sind deaktiviert; gemergte Arbeitsbranches werden anschließend entfernt.

Copyright © Cem Firat. Alle Rechte vorbehalten.
