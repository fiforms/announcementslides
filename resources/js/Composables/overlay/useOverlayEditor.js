import { computed, ref } from 'vue';
import { cloneElements } from './model.js';

const HISTORY_LIMIT = 50;

// Editor state shared (via provide/inject) by the overlay editor's
// components: the element list, the selection, and an undo/redo history of
// shallow snapshots (heavy strings are shared between snapshots, not
// copied). Callers mutate elements freely and call commit() once a change
// is complete (pointer up, input change) to record an undo step.
export function useOverlayEditor() {
    const elements = ref([]);
    const selectedId = ref(null);
    const dirty = ref(false);
    const history = ref([]);
    const historyIndex = ref(-1);

    const selected = computed(() => elements.value.find(el => el.id === selectedId.value) ?? null);
    const canUndo = computed(() => historyIndex.value > 0);
    const canRedo = computed(() => historyIndex.value < history.value.length - 1);

    function reset(list) {
        elements.value = list;
        selectedId.value = null;
        history.value = [cloneElements(list)];
        historyIndex.value = 0;
        dirty.value = false;
    }

    function commit() {
        const next = history.value.slice(0, historyIndex.value + 1);
        next.push(cloneElements(elements.value));
        if (next.length > HISTORY_LIMIT) next.shift();
        history.value = next;
        historyIndex.value = next.length - 1;
        dirty.value = true;
    }

    function restore(index) {
        historyIndex.value = index;
        elements.value = cloneElements(history.value[index]);
        if (!elements.value.some(el => el.id === selectedId.value)) selectedId.value = null;
        dirty.value = true;
    }

    const undo = () => canUndo.value && restore(historyIndex.value - 1);
    const redo = () => canRedo.value && restore(historyIndex.value + 1);

    function find(id) {
        return elements.value.find(el => el.id === id);
    }

    function add(el) {
        elements.value.push(el);
        selectedId.value = el.id;
        commit();
    }

    function update(id, patch, record = true) {
        const el = find(id);
        if (!el) return;
        Object.assign(el, patch);
        if (record) commit();
    }

    function remove(id) {
        elements.value = elements.value.filter(el => el.id !== id);
        if (selectedId.value === id) selectedId.value = null;
        commit();
    }

    // delta +1 moves the element one layer up (towards the front).
    function move(id, delta) {
        const list = [...elements.value];
        const from = list.findIndex(el => el.id === id);
        const to = from + delta;
        if (from < 0 || to < 0 || to >= list.length) return;
        [list[from], list[to]] = [list[to], list[from]];
        elements.value = list;
        commit();
    }

    function select(id) {
        selectedId.value = id;
    }

    return {
        elements, selectedId, selected, dirty, canUndo, canRedo,
        reset, commit, undo, redo, find, add, update, remove, move, select,
        markSaved: () => { dirty.value = false; },
    };
}
