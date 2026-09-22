/**
 * Prüft, ob die Admin-Oberflächen einer Erweiterung an ein Recht gebunden sind.
 *
 * Verwendung im Plugin — eine Datei neben dem Admin-Quellcode, fünf Zeilen:
 *
 *     import checkAclWiring from '../../../../../vendor/depa/shopware-extension-checks/js/acl-wiring';
 *
 *     it('bindet jede Admin-Oberfläche an ein Recht', async () => {
 *         const { modules, open } = await checkAclWiring(() => import('./main.js'));
 *
 *         expect(modules.length).toBeGreaterThan(0);
 *         expect(open).toEqual([]);
 *     });
 *
 * **Beide Richtungen des Fehlers sind still, und beide sieht der Entwickler nicht**, weil er
 * Administrator ist: `AclService.can()` liefert `true`, sobald gar kein Recht dransteht
 * (`acl.service.ts:12`) — die Oberfläche ist dann für jeden offen. Und über allem steht
 * `isAdmin()` und kürzt die Prüfung weg. Beim Durchklicken fällt nichts auf.
 *
 * Gelesen wird das **echte registrierte Objekt**, nicht der Dateitext. Eine Vorgänger-Fassung las
 * den Quelltext mit gezählten Klammern und brauchte dafür 469 Zeilen — dieselbe Auskunft.
 *
 * **Der Lader wird übergeben und nicht importiert.** Damit steht der Stub garantiert, bevor der
 * Admin-Code läuft: Ein Import hier oben würde vorgezogen, und die Reihenfolge hinge an einer
 * Zeilenfolge, die „Imports sortieren" jederzeit umschreibt. Deshalb ist die Funktion async —
 * `import()` ist die einzige Form, mit der sich Laden verzögern lässt.
 */

/**
 * Antwortet auf alles mit sich selbst und ist dabei aufrufbar, ableitbar und indizierbar.
 *
 * Damit braucht der Stub nie eine Liste der Shopware-Dienste, die ein Modul beim Laden anfasst —
 * die wuchs sonst mit jedem Plugin (gemessen: sechs Nachträge über zwei Plugins).
 */
const anything = new Proxy(function () {}, {
    get: (target, name) => (name === 'prototype' ? target.prototype : anything),
    apply: () => anything,
    construct: () => ({}),
});

/**
 * Lädt den Admin-Code und gibt zurück, welche Module sich angemeldet haben und welche
 * Oberflächen dabei ohne Recht auskamen.
 *
 * @param {() => Promise<unknown> | unknown} loadTheAdmin Im Plugin `() => import('./main.js')`.
 * @returns {Promise<{ modules: string[], open: string[] }>}
 */
module.exports = async function checkAclWiring(loadTheAdmin) {
    const modules = [];

    global.Shopware = new Proxy(
        { Module: { register: (name, definition) => modules.push({ name, definition }) } },
        { get: (own, name) => own[name] ?? anything },
    );

    await loadTheAdmin();

    const open = [];

    modules.forEach(({ name, definition }) => {
        Object.entries(definition.routes ?? {}).forEach(([route, { redirect, meta }]) => {
            // Eine Route, die nur weiterleitet, braucht kein Recht — das sitzt am Ziel.
            // Shopware macht das selbst so (`sw-landing-page`).
            const onlyForwards = Boolean(redirect);

            if (!onlyForwards && !meta?.privilege) {
                open.push(`${name}: Route ${route}`);
            }
        });

        // Menüeintrag und Einstellungskachel tragen ihr Recht direkt, nicht in `meta`.
        (definition.navigation ?? []).forEach(({ path, privilege }) => {
            if (!privilege) {
                open.push(`${name}: Menüeintrag ${path}`);
            }
        });

        if (definition.settingsItem && !definition.settingsItem.privilege) {
            open.push(`${name}: Einstellungskachel`);
        }
    });

    return { modules: modules.map(({ name }) => name), open };
};

/** Prüft die Prüfung: `node js/acl-wiring.js` — wirft, wenn eine der vier Aussagen nicht hält. */
if (require.main === module) {
    const assert = require('node:assert');

    (async () => {
        const broken = await module.exports(() => {
            Shopware.Module.register('ohne-rechte', {
                routes: {
                    list: { component: 'x-list', path: 'list' },
                    detail: { component: 'x-detail', path: 'detail/:id', meta: { parentPath: 'x.list' } },
                },
                navigation: [{ label: 'X', path: 'x.list' }],
                settingsItem: { to: 'x.list' },
            });
        });

        assert.deepStrictEqual(broken.open, [
            'ohne-rechte: Route list',
            'ohne-rechte: Route detail',
            'ohne-rechte: Menüeintrag x.list',
            'ohne-rechte: Einstellungskachel',
        ]);

        const sound = await module.exports(() => {
            Shopware.Module.register('mit-rechten', {
                routes: {
                    list: { component: 'x-list', path: 'list', meta: { privilege: 'x.viewer' } },
                    // Nur eine Weiterleitung, also ohne eigenes Recht richtig.
                    index: { component: 'x-list', path: 'index', redirect: { name: 'x.list' } },
                },
                navigation: [{ label: 'X', path: 'x.list', privilege: 'x.viewer' }],
                settingsItem: { to: 'x.list', privilege: 'x.viewer' },
            });
        });

        assert.deepStrictEqual(sound.open, []);
        assert.deepStrictEqual(sound.modules, ['mit-rechten']);
        // Ein Lauf, der nichts lädt, darf nicht gegenstandslos grün aussehen.
        assert.deepStrictEqual((await module.exports(() => {})).modules, []);

        console.log('acl-wiring: alle vier Aussagen halten.');
    })();
}
