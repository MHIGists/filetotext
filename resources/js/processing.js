// Processing page specific JavaScript
import { progressTracker } from './progressTracker';
import { exportText, sendArrayToExport } from './export';

// Make progressTracker available globally for Alpine.js
window.progressTracker = progressTracker;

// Make export functions available globally for inline event handlers
window.exportText = exportText;
window.sendArrayToExport = sendArrayToExport;

