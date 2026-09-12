import { Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend, ArcElement, DoughnutController, LineController, LineElement, PointElement, Filler } from 'chart.js';
import { Html5Qrcode } from 'html5-qrcode';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    ArcElement,
    DoughnutController,
    LineController,
    LineElement,
    PointElement,
    Filler,
);

window.Chart = Chart;
window.Html5Qrcode = Html5Qrcode;

window.__qrActiveReader = null;

window.__qrChain = (function () {
    let tail = Promise.resolve();

    return (task) => {
        const next = tail.then(task, task);
        tail = next.catch(() => {});
        return next;
    };
})();

window.__qrStop = function (reader) {
    if (!reader) {
        return Promise.resolve();
    }
    return window.__qrChain(async () => {
        if (window.__qrActiveReader === reader) {
            window.__qrActiveReader = null;
        }
        try {
            await reader.stop();
        } catch {}
        try {
            reader.clear();
        } catch {}
    });
};

window.__qrStopAll = function () {
    return window.__qrChain(async () => {
        const reader = window.__qrActiveReader;
        window.__qrActiveReader = null;
        if (reader) {
            try {
                await reader.stop();
            } catch {}
            try {
                reader.clear();
            } catch {}
        }
    });
};

window.__kioskHasOpenDialog = function () {
    if (document.querySelector('ui-modal[data-open]')) {
        return true;
    }
    return [...document.querySelectorAll('[data-qr-overlay]')].some((el) => el.offsetParent !== null);
};

window.focusFirstError = function () {
    const el = document.querySelector('[data-invalid], [aria-invalid="true"]');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        el.focus({ preventScroll: true });
    }
};
