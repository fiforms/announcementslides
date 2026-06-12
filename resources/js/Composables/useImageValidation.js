export function useImageValidation() {
    const MIN_RESOLUTION_MP = 2;
    const MAX_RESOLUTION_MP = 8.5;
    const MIN_FILE_SIZE = 80 * 1024;
    const MAX_FILE_SIZE = 5 * 1024 * 1024;
    const TARGET_ASPECT_RATIO = 16 / 9;
    const ASPECT_RATIO_TOLERANCE = 0.02;

    async function getImageDimensions(file) {
        return new Promise((resolve) => {
            if (!file.type.startsWith('image/')) {
                resolve([null, null]);
                return;
            }

            const img = new Image();
            img.onload = () => resolve([img.width, img.height]);
            img.onerror = () => resolve([null, null]);
            img.src = URL.createObjectURL(file);
        });
    }

    function checkResolution(width, height) {
        const megapixels = (width * height) / 1_000_000;
        const issues = [];

        if (megapixels < MIN_RESOLUTION_MP) {
            issues.push(`Low resolution (${megapixels.toFixed(1)}MP) — image may appear pixelated`);
        }

        if (megapixels > MAX_RESOLUTION_MP) {
            issues.push(`High resolution (${megapixels.toFixed(1)}MP) — may degrade slideshow performance`);
        }

        return issues;
    }

    function checkFileSize(fileSize) {
        const issues = [];
        const sizeMB = (fileSize / (1024 * 1024)).toFixed(1);

        if (fileSize < MIN_FILE_SIZE) {
            issues.push('File too small — likely poor image quality');
        }

        if (fileSize > MAX_FILE_SIZE) {
            issues.push(`File too large (${sizeMB}MB, over 5MB) — may cause performance issues`);
        }

        return issues;
    }

    function checkAspectRatio(width, height) {
        const ratio = width / height;
        const targetRatio = TARGET_ASPECT_RATIO;
        const tolerance = targetRatio * ASPECT_RATIO_TOLERANCE;

        const minRatio = targetRatio - tolerance;
        const maxRatio = targetRatio + tolerance;

        if (ratio < minRatio || ratio > maxRatio) {
            return [`Aspect ratio ${ratio.toFixed(2)} (16:9 ± 2% recommended) — may cause letterboxing`];
        }

        return [];
    }

    async function validate(file) {
        const issues = [];

        if (!file.type.startsWith('image/')) {
            return { issues: [], width: null, height: null };
        }

        const [width, height] = await getImageDimensions(file);

        if (width && height) {
            issues.push(
                ...checkResolution(width, height),
                ...checkAspectRatio(width, height)
            );
        }

        issues.push(...checkFileSize(file.size));

        return { issues, width, height };
    }

    return { validate, getImageDimensions };
}
