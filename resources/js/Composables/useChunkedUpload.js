import { ref, computed } from 'vue';

const CHUNK_SIZE = 1.5 * 1024 * 1024;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export function useChunkedUpload(options = {}) {
    const chunkRoute    = options.chunkRoute ?? 'uploads.chunk';
    const finalizeRoute = options.finalizeRoute ?? 'uploads.finalize';
    // Overrides the default `{ uploads: [...], ...payload }` finalize body —
    // used by callers finalizing against a single-file endpoint instead of
    // the batch uploads.finalize route (see MediaManager.vue).
    const buildFinalizePayload = options.buildFinalizePayload
        ?? ((completedUploads, payload) => ({ uploads: completedUploads, ...payload }));

    const isUploading     = ref(false);
    const uploadError     = ref(null);
    const fileProgress    = ref([]);
    const overallProgress = computed(() => {
        if (!fileProgress.value.length) return 0;
        return Math.round(fileProgress.value.reduce((sum, f) => sum + f.progress, 0) / fileProgress.value.length);
    });

    async function uploadChunks(file, uploadId, mediaType, onProgress) {
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        let result = null;

        for (let i = 0; i < totalChunks; i++) {
            const start = i * CHUNK_SIZE;
            const chunk = file.slice(start, start + CHUNK_SIZE);

            const fd = new FormData();
            fd.append('upload_id',    uploadId);
            fd.append('chunk_index',  i);
            fd.append('total_chunks', totalChunks);
            fd.append('filename',     file.name);
            fd.append('media_type',   mediaType);
            fd.append('mime_type',    file.type);
            fd.append('chunk',        chunk, `chunk_${i}`);

            const { data } = await window.axios.post(route(chunkRoute), fd, {
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });

            onProgress(Math.round(((i + 1) / totalChunks) * 100));

            if (data.status === 'complete') {
                result = data;
            }
        }

        return result;
    }

    async function upload(files, payload) {
        isUploading.value  = true;
        uploadError.value  = null;
        fileProgress.value = files.map(f => ({ name: f.name, progress: 0, done: false }));

        const completedUploads = [];
        const mediaType = payload?.media_type ?? 'slide';

        try {
            for (let fi = 0; fi < files.length; fi++) {
                const file     = files[fi];
                const uploadId = crypto.randomUUID();

                const assembled = await uploadChunks(file, uploadId, mediaType, (pct) => {
                    fileProgress.value[fi].progress = pct;
                });

                fileProgress.value[fi].done = true;
                completedUploads.push(assembled);
            }

            const { data } = await window.axios.post(
                route(finalizeRoute),
                buildFinalizePayload(completedUploads, payload),
                { headers: { 'X-CSRF-TOKEN': csrfToken() } },
            );

            return data;
        } catch (err) {
            uploadError.value = err.response?.data?.message ?? 'Upload failed. Please try again.';
            return null;
        } finally {
            isUploading.value = false;
        }
    }

    return { isUploading, uploadError, fileProgress, overallProgress, upload };
}
