/**
 * Field officer IndexedDB (docs/PWA.md). Plain IndexedDB, no wrapper library.
 * - `today`: one record (`current`), the day-list snapshot embedded in /field.
 * - `pending_collections`: the offline collection queue, keyed by client_uuid, indexed by user_id.
 * Bump VERSION and extend upgrade() when adding stores.
 */
const DB_NAME = 'srijonshil-field';
const VERSION = 2;
const TODAY = 'today';
const CURRENT = 'current';
const QUEUE = 'pending_collections';

function open() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, VERSION);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(TODAY)) {
                db.createObjectStore(TODAY);
            }
            if (!db.objectStoreNames.contains(QUEUE)) {
                db.createObjectStore(QUEUE, { keyPath: 'client_uuid' }).createIndex('user_id', 'user_id');
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** Runs requests on a store and resolves with the last one's result once the transaction commits. */
async function run(store, mode, action) {
    const db = await open();
    try {
        return await new Promise((resolve, reject) => {
            const transaction = db.transaction(store, mode);
            const request = action(transaction.objectStore(store));
            transaction.oncomplete = () => resolve(request?.result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    } finally {
        db.close();
    }
}

export const saveToday = (snapshot) => run(TODAY, 'readwrite', (store) => store.put(snapshot, CURRENT));
export const loadToday = () => run(TODAY, 'readonly', (store) => store.get(CURRENT));
export const clearToday = () => run(TODAY, 'readwrite', (store) => store.delete(CURRENT));

/** Every queued item of one user (any state). */
export const queuedFor = (userId) => run(QUEUE, 'readonly', (store) => store.index('user_id').getAll(userId));
export const enqueue = (item) => run(QUEUE, 'readwrite', (store) => store.add(item));
/** Deletes one or several queue records (by client_uuid) in one transaction. */
export const removeQueued = (clientUuids) => run(QUEUE, 'readwrite', (store) => {
    [].concat(clientUuids).forEach((clientUuid) => store.delete(clientUuid));
    return null;
});

/** Writes several queue records in one transaction. */
export const putQueued = (items) => run(QUEUE, 'readwrite', (store) => {
    let last = null;
    items.forEach((item) => {
        last = store.put(item);
    });
    return last;
});

/** "Fix and resend": the corrected item (new client_uuid) replaces the rejected one atomically. */
export const replaceQueued = (oldClientUuid, item) => run(QUEUE, 'readwrite', (store) => {
    store.delete(oldClientUuid);
    return store.add(item);
});
