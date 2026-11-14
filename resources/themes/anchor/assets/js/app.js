import sosView from './sosview';
import ApexCharts from 'apexcharts';
import * as Diff from 'diff';
import { Diff2HtmlUI } from 'diff2html/lib/ui/js/diff2html-ui.js';
import 'diff2html/bundles/css/diff2html.min.css';

window.sosViewer = new sosView();
window.ApexCharts = ApexCharts;
window.Diff = Diff;
window.Diff2HtmlUI = Diff2HtmlUI;
