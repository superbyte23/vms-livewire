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
