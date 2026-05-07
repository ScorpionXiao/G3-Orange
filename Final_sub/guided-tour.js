/**
 * StopAtlas — Guided Investigative Tour
 * Add <script src="guided-tour.js"></script> to the <head> of:
 *   index.html, analysis.html, geography.html,
 *   time.html, outcome.html, demographics.html
 *
 * On index.html CTA button add: onclick="GuidedTour.start(); return false;"
 */

(function () {
  'use strict';

  const KEY_ACTIVE = 'guidedTour';
  const KEY_STEP   = 'tourStep';

  const page = (function () {
    const p = location.pathname.split('/').pop().toLowerCase().replace(/\?.*$/, '');
    if (p === '' || p === 'index.html') return 'index';
    if (p === 'analysis.html')          return 'analysis';
    if (p === 'geography.html')         return 'geography';
    if (p === 'time.html')              return 'time';
    if (p === 'outcome.html')           return 'outcome';
    if (p === 'demographics.html')      return 'demographics';
    return null;
  })();

  const CSS = `
    #gt-overlay {
      position: fixed; inset: 0; z-index: 8000;
      background: transparent;
      opacity: 0; pointer-events: none;
      transition: opacity 0.4s ease;
    }
    #gt-overlay.gt-visible { opacity: 1; pointer-events: all; }

    #gt-tooltip {
      position: fixed;
      right: 24px; bottom: 24px;
      left: auto; top: auto;
      transform: translateY(12px);
      z-index: 8002;
      width: 320px;
      background: #fbf3e8;
      border: 1px solid #e2d6c6;
      border-left: 4px solid #cf864b;
      border-radius: 10px;
      padding: 22px 24px 18px;
      box-shadow: 0 20px 60px rgba(42,38,34,0.32);
      font-family: 'DM Sans', sans-serif;
      opacity: 0; pointer-events: none;
      transition: opacity 0.35s ease, transform 0.35s ease;
    }
    #gt-tooltip.gt-visible {
      opacity: 1; pointer-events: all;
      transform: translateY(0);
    }

    #gt-arrow {
      position: fixed;
      z-index: 8003;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      pointer-events: none;
      transition: left 0.5s cubic-bezier(0.4,0,0.2,1), top 0.5s cubic-bezier(0.4,0,0.2,1);
    }
    #gt-arrow.gt-visible { display: flex; }

    .gt-arrow-label {
      font-family: 'IBM Plex Mono', monospace;
      font-size: 10px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: #cf864b;
      background: rgba(251,243,232,0.96);
      border: 1px solid #e2d6c6;
      border-radius: 4px;
      padding: 3px 8px;
      white-space: nowrap;
    }
    .gt-arrow-icon {
      font-size: 28px;
      line-height: 1;
      animation: gt-bounce 0.9s ease-in-out infinite;
      color: #cf864b;
      filter: drop-shadow(0 2px 6px rgba(207,134,75,0.5));
    }
    @keyframes gt-bounce {
      0%, 100% { transform: translateY(0); }
      50%       { transform: translateY(8px); }
    }

    .gt-step-badge {
      font-family: 'IBM Plex Mono', monospace;
      font-size: 9px; letter-spacing: 0.15em;
      text-transform: uppercase; color: #cf864b;
      margin-bottom: 10px; display: flex;
      align-items: center; gap: 10px;
    }
    .gt-step-dots { display: flex; gap: 4px; }
    .gt-step-dot { width: 5px; height: 5px; border-radius: 50%; background: #e2d6c6; transition: background 0.3s; }
    .gt-step-dot.active { background: #cf864b; }

    .gt-title {
      font-family: 'DM Serif Display', serif;
      font-size: 19px; letter-spacing: -0.3px;
      color: #424242; margin-bottom: 8px; line-height: 1.2;
    }
    .gt-body {
      font-size: 13px; line-height: 1.7;
      color: #7a746b; margin-bottom: 18px;
    }
    .gt-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .gt-btn-next {
      flex: 1; padding: 9px 16px;
      background: #424242; color: #fbf3e8;
      border: none; border-radius: 6px;
      font-family: 'DM Sans', sans-serif;
      font-size: 12px; font-weight: 600;
      letter-spacing: 0.02em; cursor: pointer;
      transition: background 0.15s, transform 0.1s;
    }
    .gt-btn-next:hover { background: #cf864b; transform: translateY(-1px); }
    .gt-btn-skip {
      font-size: 11px; color: #b0a89e;
      background: none; border: none; cursor: pointer;
      font-family: 'DM Sans', sans-serif;
      padding: 4px; transition: color 0.15s; white-space: nowrap;
    }
    .gt-btn-skip:hover { color: #7a746b; }
    #gt-close {
      position: absolute; top: 12px; right: 14px;
      font-size: 16px; color: #b0a89e;
      background: none; border: none; cursor: pointer;
      line-height: 1; transition: color 0.15s;
    }
    #gt-close:hover { color: #424242; }
  `;

  function injectStyles() {
    if (document.getElementById('gt-styles')) return;
    const s = document.createElement('style');
    s.id = 'gt-styles'; s.textContent = CSS;
    document.head.appendChild(s);
  }

  function buildDOM() {
    if (document.getElementById('gt-overlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'gt-overlay';
    overlay.onclick = () => GuidedTour.dismiss();

    const arrow = document.createElement('div');
    arrow.id = 'gt-arrow';
    arrow.innerHTML = '<div class="gt-arrow-label" id="gt-arrow-label">Look here</div><div class="gt-arrow-icon">↓</div>';

    const tooltip = document.createElement('div');
    tooltip.id = 'gt-tooltip';
    tooltip.innerHTML = `
      <button id="gt-close" onclick="GuidedTour.dismiss()">✕</button>
      <div class="gt-step-badge">
        <span id="gt-step-label">Investigation</span>
        <div class="gt-step-dots" id="gt-dots"></div>
      </div>
      <div class="gt-title" id="gt-title"></div>
      <div class="gt-body"  id="gt-body"></div>
      <div class="gt-actions">
        <button class="gt-btn-skip" onclick="GuidedTour.dismiss()">Skip tour</button>
        <button class="gt-btn-next" id="gt-next-btn">Continue</button>
      </div>`;
    tooltip.onclick = e => e.stopPropagation();

    document.body.appendChild(overlay);
    document.body.appendChild(arrow);
    document.body.appendChild(tooltip);
  }

  function pointArrowAt(el, label) {
    if (!el) { hideArrow(); return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
      const rect = el.getBoundingClientRect();
      const arrow = document.getElementById('gt-arrow');
      const lbl   = document.getElementById('gt-arrow-label');
      arrow.style.left = (rect.left + rect.width / 2 - 20) + 'px';
      arrow.style.top  = Math.max(80, rect.top - 64) + 'px';
      if (lbl) lbl.textContent = label || 'Look here';
      arrow.classList.add('gt-visible');
    }, 600);
  }

  function hideArrow() {
    const a = document.getElementById('gt-arrow');
    if (a) a.classList.remove('gt-visible');
  }

  function showTooltip(stepLabel, title, body, btnText, total, current) {
    document.getElementById('gt-step-label').textContent = stepLabel;
    document.getElementById('gt-title').textContent = title;
    document.getElementById('gt-body').textContent  = body;
    document.getElementById('gt-next-btn').textContent = btnText;
    const dots = document.getElementById('gt-dots');
    dots.innerHTML = '';
    for (let i = 0; i < total; i++) {
      const d = document.createElement('div');
      d.className = 'gt-step-dot' + (i === current ? ' active' : '');
      dots.appendChild(d);
    }
    document.getElementById('gt-tooltip').classList.add('gt-visible');
  }

  function showOverlay() { document.getElementById('gt-overlay').classList.add('gt-visible'); }

  function hideAll() {
    ['gt-overlay','gt-tooltip','gt-arrow'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.classList.remove('gt-visible');
    });
  }

  function setNext(fn) { document.getElementById('gt-next-btn').onclick = fn; }

  let subStep = 0;

  /* ANALYSIS */
  function runAnalysis() {
    showOverlay();
    pointArrowAt(document.getElementById('card-geo'), 'Start here');
    showTooltip('Step 1 of 5 — Where',
      'Traffic stops are not evenly distributed.',
      'We begin by asking where stops are concentrated across San Francisco. Certain districts account for a disproportionate share of all recorded stops.',
      'Begin Investigation →', 5, 0);
    setNext(() => {
      sessionStorage.setItem(KEY_STEP, '2');
      hideAll(); window.location.href = 'geography.html';
    });
  }

  /* GEOGRAPHY */
  const geoBeats = [
    { id: 'table1-body', label: 'District table',
      title: 'Some districts carry a heavier load.',
      body: 'A small number of police districts account for a disproportionate share of all recorded stops. The top rows represent the most heavily-policed parts of the city.',
      btn: 'Show me the map →' },
    { id: 'geoMap', label: 'Stop location map',
      title: 'Stops cluster in identifiable corridors.',
      body: 'Repeated stops accumulate along specific streets — particularly in the northeastern and southeastern quadrants. This density is not random.',
      btn: 'Continue to Time Patterns →', final: true },
  ];

  function runGeography() { showOverlay(); subStep = 0; runGeoBeat(); }
  function runGeoBeat() {
    const b = geoBeats[subStep];
    pointArrowAt(document.getElementById(b.id), b.label);
    showTooltip('Step 2 of 5 — Where', b.title, b.body, b.btn, 5, 1);
    setNext(() => {
      if (b.final) { sessionStorage.setItem(KEY_STEP,'3'); hideAll(); window.location.href='time.html'; }
      else { subStep++; runGeoBeat(); }
    });
  }

  /* TIME */
  const timeBeats = [
    { id: 'c2a', label: 'Hourly chart',
      title: 'Stop activity rises sharply in the evening.',
      body: 'The hourly chart shows that traffic stops peak in late afternoon and evening — tied to daily mobility patterns, not random enforcement.',
      btn: 'Show weekday pattern →' },
    { id: 'c2b', label: 'Weekday chart',
      title: 'Patterns also shift across the week.',
      body: 'Weekday totals differ from weekend totals, suggesting enforcement responds to the broader rhythm of the city.',
      btn: 'Continue to Outcomes →', final: true },
  ];

  function runTime() { showOverlay(); subStep = 0; runTimeBeat(); }
  function runTimeBeat() {
    const b = timeBeats[subStep];
    const el = document.getElementById(b.id);
    pointArrowAt(el ? (el.closest('.chart-card') || el) : null, b.label);
    showTooltip('Step 3 of 5 — When', b.title, b.body, b.btn, 5, 2);
    setNext(() => {
      if (b.final) { sessionStorage.setItem(KEY_STEP,'4'); hideAll(); window.location.href='outcome.html'; }
      else { subStep++; runTimeBeat(); }
    });
  }

  /* OUTCOME */
  const outcomeBeats = [
    { id: 'c3a', label: 'Bubble chart',
      title: 'Different stop reasons produce very different outcomes.',
      body: 'The bubble chart maps arrest rate vs search rate. Targeted stops like BOLO/Warrant sit in the high-arrest corner — far from routine traffic violations.',
      btn: 'Show search effectiveness →' },
    { id: 'c3b-custom', label: 'Hit rate chart',
      title: 'Search effectiveness varies sharply by stop type.',
      body: 'When officers search a vehicle, how often do they find contraband? The answer depends heavily on why the stop was made.',
      btn: 'Continue to Demographics →', final: true },
  ];

  function runOutcome() { showOverlay(); subStep = 0; runOutcomeBeat(); }
  function runOutcomeBeat() {
    const b = outcomeBeats[subStep];
    const el = document.getElementById(b.id);
    pointArrowAt(el ? (el.closest('.chart-card') || el) : null, b.label);
    showTooltip('Step 4 of 5 — What Happens', b.title, b.body, b.btn, 5, 3);
    setNext(() => {
      if (b.final) { sessionStorage.setItem(KEY_STEP,'5'); hideAll(); window.location.href='demographics.html'; }
      else { subStep++; runOutcomeBeat(); }
    });
  }

  /* DEMOGRAPHICS */
  const demoBeats = [
    { id: 'c4a', label: 'Race outcome chart',
      title: 'Outcome distributions differ across racial groups.',
      body: 'The stacked bars compare warning, citation, and arrest shares within each racial group. The arrest segment shifts meaningfully across groups.',
      btn: 'Show arrest rates →' },
    { id: 'c4b', label: 'Arrest rate chart',
      title: 'These patterns are persistent.',
      body: 'Isolating arrest rate alone makes the disparity clearer. Darker bars mark groups with rates above the dataset midpoint — consistent across 2007–2016.',
      btn: 'Explore the Data Yourself →', final: true },
  ];

  function runDemographics() { showOverlay(); subStep = 0; runDemoBeat(); }
  function runDemoBeat() {
    const b = demoBeats[subStep];
    const el = document.getElementById(b.id);
    pointArrowAt(el ? (el.closest('.chart-card') || el.closest('.analysis-chart') || el) : null, b.label);
    showTooltip('Step 5 of 5 — Who', b.title, b.body, b.btn, 5, 4);
    setNext(() => {
      if (b.final) {
        sessionStorage.removeItem(KEY_ACTIVE); sessionStorage.removeItem(KEY_STEP);
        hideAll(); window.location.href='map.html';
      } else { subStep++; runDemoBeat(); }
    });
  }

  /* PUBLIC */
  window.GuidedTour = {
    start: function () {
      sessionStorage.setItem(KEY_ACTIVE, 'true');
      sessionStorage.setItem(KEY_STEP, '1');
      sessionStorage.setItem('tourEntry', 'true');
      window.location.href = 'analysis.html';
    },
    dismiss: function () {
      hideAll();
      sessionStorage.removeItem(KEY_ACTIVE);
      sessionStorage.removeItem(KEY_STEP);
    },
    autoRun: function () {
      if (sessionStorage.getItem(KEY_ACTIVE) !== 'true') return;
      if (!page) return;
      injectStyles(); buildDOM();
      const step  = parseInt(sessionStorage.getItem(KEY_STEP) || '0', 10);
      const delay = page === 'analysis' ? 600 : 1400;
      setTimeout(() => {
        if (page === 'analysis'     && step === 1) runAnalysis();
        if (page === 'geography'    && step === 2) runGeography();
        if (page === 'time'         && step === 3) runTime();
        if (page === 'outcome'      && step === 4) runOutcome();
        if (page === 'demographics' && step === 5) runDemographics();
      }, delay);
    },
    startFromAnalysis: function () {
      sessionStorage.setItem(KEY_ACTIVE, 'true');
      sessionStorage.setItem(KEY_STEP, '1');
      sessionStorage.setItem('tourEntry', 'true');
      // Already on analysis.html so just run directly
      injectStyles();
      buildDOM();
      runAnalysis();
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', GuidedTour.autoRun);
  } else {
    GuidedTour.autoRun();
  }

})();
