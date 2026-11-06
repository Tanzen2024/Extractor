'use strict';

const test = require('node:test');
const assert = require('node:assert');
const sync = require('../../public/assets/js/sync-status.js');

const base = {
    state: 'fresh', running: false, lastSuccessAt: '2026-11-06T05:30:00+01:00', sourceUpdatedAt: '2026-11-06 04:32:26',
    lastStatus: 'success', lastReason: null, nextRunAt: '2026-11-07T05:00:00+01:00', clients: 2453111,
};

function fact(view, label) {
    return view.facts.find((f) => f.label === label).value;
}

test('the three required states have their label', () => {
    assert.strictEqual(sync.describe({ ...base, state: 'fresh' }).label, 'À jour');
    assert.strictEqual(sync.describe({ ...base, state: 'late' }).label, 'En retard');
    assert.strictEqual(sync.describe({ ...base, state: 'failed' }).label, 'Échec');
});

test('shows the five required facts', () => {
    const view = sync.describe(base);

    assert.strictEqual(fact(view, 'Dernière synchronisation réussie'), '06/11/2026 05:30');
    assert.strictEqual(fact(view, 'Dernière tentative'), 'Réussie');
    assert.strictEqual(fact(view, 'Clients synchronisés').replace(/\s/g, ' '), '2 453 111');
    assert.strictEqual(fact(view, 'Chargement Oracle'), '06/11/2026 04:32');
    assert.strictEqual(fact(view, 'Prochaine synchronisation'), '07/11/2026 05:00');
});

test('a failure gives its reason in plain words, never a code', () => {
    const view = sync.describe({ ...base, state: 'failed', lastStatus: 'failed', lastReason: 'row_count_mismatch' });
    const text = fact(view, 'Dernière tentative');

    assert.match(text, /^Échouée — /);
    assert.doesNotMatch(text, /row_count_mismatch/);
    assert.strictEqual(sync.reasonText('some_new_reason'), 'Voir le journal de synchronisation');
});

test('announces no run when nothing is scheduled', () => {
    assert.strictEqual(fact(sync.describe({ ...base, nextRunAt: null }), 'Prochaine synchronisation'), 'Non programmée');
});

test('nothing ever synchronised reads as such, not as a date', () => {
    const view = sync.describe({ state: 'late', running: false, lastSuccessAt: null, sourceUpdatedAt: null, lastStatus: null, lastReason: null, nextRunAt: null, clients: null });

    assert.strictEqual(fact(view, 'Dernière synchronisation réussie'), 'Aucune');
    assert.strictEqual(fact(view, 'Clients synchronisés'), 'Aucune');
});

test('history row: an unknown figure stays a dash, zero stays zero', () => {
    const cells = sync.historyCells({
        startedAt: '2026-11-06 10:05:38', durationSeconds: 125, trigger: 'scheduled', status: 'success',
        rowsRead: 3328478, rowsAdded: 0, rowsModified: null, rowsIgnored: null, rowsRemoved: null, errorMessage: null, errorReason: null,
    });

    assert.strictEqual(cells[0], '06/11/2026 10:05');
    assert.strictEqual(cells[1], '2 min 5 s');
    assert.strictEqual(cells[2], 'Planifiée');
    assert.strictEqual(cells[3], 'Réussie');
    assert.strictEqual(cells[5], '0');
    assert.strictEqual(cells[6], '–');
});

test('history shows the technical message when the server allowed it, else the plain reason', () => {
    const run = { startedAt: '2026-11-06 10:05:38', durationSeconds: 3, trigger: 'manual', status: 'failed', errorReason: 'disk_space' };

    assert.strictEqual(sync.historyCells({ ...run, errorMessage: 'Espace disque : 1,2 Go' })[9], 'Espace disque : 1,2 Go');
    assert.strictEqual(sync.historyCells({ ...run, errorMessage: null })[9], 'Espace disque insuffisant');
});
