<?php
/*
  MICRO REMOTE V8 - BROWSER DIRECT

  Denon CEOL / RCD-N9 browser-to-LAN remote.
  Single-file PHP 7.2-compatible UI. PHP only renders the page.

  IMPORTANT:
  - Commands are sent by JavaScript from the user's browser directly to the Denon HTTP interface.
  - The PHP/web server does NOT need access to the Denon LAN.
  - Normal browser JavaScript cannot open Denon's raw TCP port 23, so this version uses Denon's HTTP goform API.
  - If this page is served over HTTPS while the Denon only offers HTTP, the browser may block the request as mixed content.
*/

declare(strict_types=1);

const APP_TITLE = 'CEOL N9 Micro Command Deck';
const APP_VERSION = 'micro-remote-v8-browser-direct-2026-08-28';

function h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$sources = array(
  array('SICD', 'CD', 'optical disc'),
  array('SITUNER', 'Tuner', 'radio deck'),
  array('SIFM', 'FM', 'frequency mode'),
  array('SIAM', 'AM', 'frequency mode'),
  array('SIIRADIO', 'Internet Radio', 'stream matrix'),
  array('SISERVER', 'Server', 'media library'),
  array('SIUSB', 'USB', 'local port'),
  array('SIBLUETOOTH', 'Bluetooth', 'wireless link'),
  array('SIDIGITALIN1', 'Digital 1', 'optical/coax'),
  array('SIDIGITALIN2', 'Digital 2', 'optical/coax'),
  array('SIANALOGIN', 'Analog', 'line input')
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(APP_TITLE) ?></title>
  <style>
    :root {
      --bg: #05070b;
      --panel: rgba(12, 19, 31, 0.78);
      --panel-2: rgba(18, 29, 47, 0.76);
      --line: rgba(94, 234, 212, 0.26);
      --cyan: #5eead4;
      --cyan-2: #22d3ee;
      --blue: #60a5fa;
      --amber: #fbbf24;
      --red: #fb7185;
      --green: #86efac;
      --text: #e8fbff;
      --muted: #8aa4b7;
      --shadow: 0 0 38px rgba(34, 211, 238, 0.13), 0 18px 60px rgba(0, 0, 0, 0.45);
      --radius: 26px;
      --vol: 0%;
    }

    * { box-sizing: border-box; }

    html, body { min-height: 100%; }

    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at 20% 20%, rgba(34, 211, 238, 0.16), transparent 30%),
        radial-gradient(circle at 82% 10%, rgba(96, 165, 250, 0.18), transparent 34%),
        radial-gradient(circle at 50% 90%, rgba(94, 234, 212, 0.12), transparent 42%),
        linear-gradient(135deg, #02040a 0%, #07101d 48%, #03070e 100%);
      overflow-x: hidden;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background:
        linear-gradient(rgba(255,255,255,0.032) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.032) 1px, transparent 1px);
      background-size: 48px 48px;
      mask-image: radial-gradient(circle at center, rgba(0,0,0,0.9), transparent 76%);
    }

    body::after {
      content: "";
      position: fixed;
      inset: 0;
      pointer-events: none;
      background: repeating-linear-gradient(0deg, rgba(255,255,255,0.028), rgba(255,255,255,0.028) 1px, transparent 1px, transparent 5px);
      mix-blend-mode: overlay;
      opacity: 0.28;
    }

    button, input, select { font: inherit; }

    .wrap {
      width: min(1440px, calc(100vw - 28px));
      margin: 0 auto;
      padding: 24px 0 36px;
      position: relative;
      z-index: 1;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 20px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .logo {
      width: 58px;
      height: 58px;
      border-radius: 18px;
      position: relative;
      background:
        radial-gradient(circle at 50% 50%, rgba(94, 234, 212, 0.9), rgba(34, 211, 238, 0.18) 38%, transparent 40%),
        conic-gradient(from 210deg, rgba(94,234,212,0.95), rgba(96,165,250,0.2), rgba(251,191,36,0.55), rgba(94,234,212,0.95));
      box-shadow: 0 0 35px rgba(94, 234, 212, 0.28), inset 0 0 24px rgba(0,0,0,0.5);
    }

    .logo::before, .logo::after {
      content: "";
      position: absolute;
      border: 1px solid rgba(232,251,255,0.4);
      border-radius: 50%;
      inset: 11px;
    }

    .logo::after { inset: 21px; border-color: rgba(251,191,36,0.58); }

    h1 {
      margin: 0;
      font-size: clamp(24px, 3vw, 42px);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      line-height: 1;
    }

    .subtitle {
      margin-top: 7px;
      color: var(--muted);
      letter-spacing: 0.2em;
      text-transform: uppercase;
      font-size: 11px;
    }

    .top-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }

    .ip-pill, .status-pill, .tiny-pill {
      border: 1px solid rgba(94, 234, 212, 0.22);
      background: rgba(7, 12, 22, 0.62);
      border-radius: 999px;
      padding: 10px 14px;
      box-shadow: inset 0 0 18px rgba(34, 211, 238, 0.05);
      color: var(--muted);
      font-size: 13px;
      letter-spacing: 0.04em;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 9px;
    }

    .dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--red);
      box-shadow: 0 0 14px var(--red);
    }

    .dot.online { background: var(--green); box-shadow: 0 0 15px var(--green); }


    .mini-remote {
      position: relative;
      display: grid;
      justify-items: center;
      gap: 18px;
      width: min(560px, 100%);
      margin: 0 auto 22px;
      isolation: isolate;
      padding: 22px 18px 24px;
      border: 1px solid rgba(94, 234, 212, 0.22);
      border-radius: 34px;
      background:
        radial-gradient(circle at 50% 0%, rgba(94, 234, 212, 0.13), transparent 42%),
        linear-gradient(180deg, rgba(18, 29, 47, 0.82), rgba(4, 9, 17, 0.92));
      box-shadow: 0 22px 70px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.07);
      overflow: hidden;
    }

    .mini-remote::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background:
        radial-gradient(circle at 50% 50%, transparent 0 56%, rgba(94, 234, 212, 0.08) 57%, transparent 58%),
        linear-gradient(115deg, transparent 0 42%, rgba(255, 255, 255, 0.06) 48%, transparent 56%);
      opacity: 0.65;
    }

    .mini-volume-chip {
      position: absolute;
      top: 16px;
      right: 16px;
      z-index: 2;
      min-width: 48px;
      height: 34px;
      padding: 0 11px;
      border: 1px solid rgba(94, 234, 212, 0.34);
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 16px;
      font-weight: 800;
      letter-spacing: 0.08em;
      color: #e8fbff;
      background: rgba(0, 7, 14, 0.72);
      box-shadow: inset 0 0 18px rgba(94, 234, 212, 0.10), 0 0 22px rgba(94, 234, 212, 0.08);
      text-shadow: 0 0 12px rgba(94, 234, 212, 0.60);
      pointer-events: none;
    }

    .mini-volume-chip::before {
      content: "VOL";
      margin-right: 7px;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.14em;
      color: rgba(191, 252, 255, 0.55);
    }

    .mini-row {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 18px;
      width: 100%;
    }

    .mini-display {
      position: relative;
      z-index: 1;
      width: min(390px, 92%);
      padding: 13px 15px;
      border: 1px solid rgba(94, 234, 212, 0.20);
      border-radius: 20px;
      background:
        linear-gradient(180deg, rgba(3, 10, 16, 0.92), rgba(0, 3, 8, 0.96)),
        radial-gradient(circle at 50% 0%, rgba(94, 234, 212, 0.10), transparent 54%);
      box-shadow: inset 0 0 28px rgba(94, 234, 212, 0.08), 0 12px 34px rgba(0, 0, 0, 0.32);
      overflow: hidden;
    }

    .mini-display::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: repeating-linear-gradient(180deg, rgba(255, 255, 255, 0.04) 0, rgba(255, 255, 255, 0.04) 1px, transparent 1px, transparent 7px);
      opacity: 0.22;
    }

    .mini-display-lines {
      position: relative;
      z-index: 1;
      display: grid;
      gap: 5px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: clamp(12px, 2.8vw, 15px);
      line-height: 1.12;
      letter-spacing: 0.08em;
      color: #bffcff;
      text-shadow: 0 0 12px rgba(94, 234, 212, 0.48);
    }

    .mini-display-line {
      min-height: 1.12em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      opacity: 0.98;
    }

    .mini-display-line.empty { opacity: 0.38; }

    .mini-btn {
      width: 74px;
      height: 74px;
      border: 1px solid rgba(232, 251, 255, 0.13);
      border-radius: 50%;
      color: rgba(232, 251, 255, 0.9);
      background:
        radial-gradient(circle at 50% 25%, rgba(255, 255, 255, 0.08), transparent 42%),
        linear-gradient(180deg, rgba(19, 33, 52, 0.92), rgba(3, 8, 15, 0.95));
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08), 0 16px 32px rgba(0, 0, 0, 0.34);
      cursor: pointer;
      display: grid;
      place-items: center;
      transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, color 0.12s ease;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }

    .mini-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(94, 234, 212, 0.55);
      color: #ffffff;
      box-shadow: 0 0 28px rgba(94, 234, 212, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.12);
    }

    .mini-btn:active {
      transform: translateY(1px) scale(0.98);
      box-shadow: inset 0 0 26px rgba(94, 234, 212, 0.12), 0 8px 18px rgba(0, 0, 0, 0.35);
    }

    .mini-btn.active {
      border-color: rgba(251, 191, 36, 0.72);
      color: #fff7d6;
      box-shadow: 0 0 30px rgba(251, 191, 36, 0.18), inset 0 0 24px rgba(251, 191, 36, 0.08);
    }

    .mini-btn.power-active {
      border-color: rgba(134, 239, 172, 0.64);
      color: #dcfce7;
      box-shadow: 0 0 32px rgba(134, 239, 172, 0.18), inset 0 0 24px rgba(134, 239, 172, 0.08);
    }

    .mini-btn.muted-active {
      border-color: rgba(251, 113, 133, 0.66);
      color: #ffe4e6;
      box-shadow: 0 0 32px rgba(251, 113, 133, 0.18), inset 0 0 24px rgba(251, 113, 133, 0.08);
    }

    .mini-btn svg {
      width: 36px;
      height: 36px;
      display: block;
      stroke: currentColor;
      fill: none;
      stroke-width: 1.85;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .mini-btn.mini-source svg { width: 34px; height: 34px; }

    .mini-pad {
      position: relative;
      z-index: 1;
      width: min(286px, 82vw);
      height: min(286px, 82vw);
      border-radius: 50%;
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      grid-template-rows: 1fr 1fr 1fr;
      gap: 10px;
      padding: 14px;
      background:
        radial-gradient(circle at 50% 50%, rgba(94, 234, 212, 0.09), transparent 52%),
        linear-gradient(180deg, rgba(9, 18, 31, 0.9), rgba(2, 6, 12, 0.96));
      border: 1px solid rgba(94, 234, 212, 0.17);
      box-shadow: inset 0 0 48px rgba(0, 0, 0, 0.45), 0 18px 44px rgba(0, 0, 0, 0.38);
    }

    .mini-pad .mini-btn {
      width: 100%;
      height: 100%;
      border-radius: 34px;
    }

    .mini-pad .mini-up { grid-column: 2; grid-row: 1; }
    .mini-pad .mini-left { grid-column: 1; grid-row: 2; }
    .mini-pad .mini-enter { grid-column: 2; grid-row: 2; border-radius: 50%; }
    .mini-pad .mini-right { grid-column: 3; grid-row: 2; }
    .mini-pad .mini-down { grid-column: 2; grid-row: 3; }

    .mini-enter svg {
      width: 42px;
      height: 42px;
      stroke-width: 1.65;
    }

    @media (max-width: 760px) {
      .mini-remote {
        position: sticky;
        top: 8px;
        z-index: 8;
        margin-top: 4px;
        border-radius: 30px;
        padding: 20px 12px 22px;
      }

      .mini-row { gap: 14px; }

      .mini-btn {
        width: 68px;
        height: 68px;
      }
    }

    @media (max-width: 430px) {
      .mini-volume-chip {
        top: 12px;
        right: 12px;
        min-width: 42px;
        height: 30px;
        font-size: 14px;
        padding: 0 9px;
      }

      .mini-row { gap: 10px; }
      .mini-btn { width: 60px; height: 60px; }
      .mini-btn svg { width: 31px; height: 31px; }
      .mini-pad { width: min(260px, 88vw); height: min(260px, 88vw); gap: 8px; padding: 12px; }
      .mini-display { width: 94%; padding: 11px 12px; border-radius: 17px; }
    }

    .deck {
      display: grid;
      grid-template-columns: 1.05fr 1.5fr 1.05fr;
      gap: 18px;
      align-items: stretch;
    }

    .panel {
      position: relative;
      border: 1px solid rgba(94, 234, 212, 0.18);
      background:
        linear-gradient(145deg, rgba(255,255,255,0.055), transparent 30%),
        linear-gradient(180deg, var(--panel), rgba(6, 12, 22, 0.82));
      border-radius: var(--radius);
      box-shadow: var(--shadow), inset 0 1px 0 rgba(255,255,255,0.06);
      overflow: hidden;
    }

    .panel::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: linear-gradient(90deg, transparent, rgba(94,234,212,0.12), transparent);
      transform: translateX(-120%);
      animation: sweep 7s linear infinite;
      opacity: 0.35;
    }

    @keyframes sweep {
      0% { transform: translateX(-120%); }
      55%, 100% { transform: translateX(120%); }
    }

    .panel-inner { position: relative; z-index: 1; padding: 20px; }

    .section-title {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 14px;
      color: #dffaff;
      text-transform: uppercase;
      letter-spacing: 0.16em;
      font-size: 12px;
      font-weight: 800;
    }

    .panel-code {
      color: var(--cyan);
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 11px;
      opacity: 0.8;
    }

    .source-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .source-btn, .cmd-btn, .ghost-btn, .danger-btn, .power-btn, .round-btn {
      border: 1px solid rgba(94, 234, 212, 0.22);
      color: var(--text);
      background:
        linear-gradient(180deg, rgba(20, 43, 64, 0.88), rgba(8, 15, 26, 0.82));
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.07), 0 10px 28px rgba(0,0,0,0.24);
      cursor: pointer;
      transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, color 0.12s ease;
      user-select: none;
    }

    .source-btn:hover, .cmd-btn:hover, .ghost-btn:hover, .danger-btn:hover, .power-btn:hover, .round-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(94,234,212,0.55);
      box-shadow: 0 0 26px rgba(34, 211, 238, 0.16), inset 0 1px 0 rgba(255,255,255,0.1);
    }

    .source-btn:active, .cmd-btn:active, .ghost-btn:active, .danger-btn:active, .power-btn:active, .round-btn:active {
      transform: translateY(1px) scale(0.99);
    }

    .source-btn {
      padding: 14px 13px;
      border-radius: 18px;
      min-height: 72px;
      text-align: left;
    }

    .source-btn strong {
      display: block;
      font-size: 15px;
      letter-spacing: 0.04em;
    }

    .source-btn span {
      display: block;
      margin-top: 4px;
      color: var(--muted);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
    }

    .source-btn.active {
      border-color: rgba(251,191,36,0.72);
      color: #fff7d6;
      box-shadow: 0 0 28px rgba(251,191,36,0.18), inset 0 0 28px rgba(251,191,36,0.06);
    }

    .display-panel { min-height: 460px; }

    .oled {
      position: relative;
      min-height: 280px;
      border-radius: 24px;
      padding: 24px 22px;
      background:
        radial-gradient(circle at 50% 0%, rgba(94,234,212,0.15), transparent 48%),
        linear-gradient(180deg, rgba(0, 16, 20, 0.94), rgba(0, 5, 10, 0.98));
      border: 1px solid rgba(94, 234, 212, 0.35);
      box-shadow: inset 0 0 40px rgba(94,234,212,0.1), 0 0 40px rgba(34,211,238,0.1);
      overflow: hidden;
    }

    .oled::before {
      content: "";
      position: absolute;
      inset: 0;
      background: repeating-linear-gradient(0deg, rgba(94,234,212,0.045), rgba(94,234,212,0.045) 1px, transparent 1px, transparent 7px);
      pointer-events: none;
    }

    .oled::after {
      content: "";
      position: absolute;
      left: -20%;
      top: -80%;
      width: 140%;
      height: 180%;
      transform: rotate(8deg);
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
      pointer-events: none;
    }

    .display-lines {
      position: relative;
      z-index: 1;
      display: grid;
      gap: 10px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      text-shadow: 0 0 12px rgba(94,234,212,0.65);
    }

    .display-line {
      min-height: 22px;
      color: var(--cyan);
      font-size: clamp(14px, 1.4vw, 19px);
      letter-spacing: 0.05em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .display-line.empty { opacity: 0.35; }

    .state-row {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      margin-top: 14px;
    }

    .state-card {
      border: 1px solid rgba(94, 234, 212, 0.14);
      background: rgba(2, 9, 17, 0.55);
      border-radius: 18px;
      padding: 14px 12px;
      min-height: 70px;
    }

    .state-card small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 10px;
      margin-bottom: 7px;
    }

    .state-card b {
      display: block;
      font-size: 17px;
      font-weight: 800;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .volume-stage {
      display: grid;
      place-items: center;
      padding: 4px 0 10px;
    }

    .volume-ring {
      width: min(290px, 72vw);
      aspect-ratio: 1;
      border-radius: 50%;
      position: relative;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle, #07101c 0 49%, transparent 50%),
        conic-gradient(var(--cyan) 0 var(--vol), rgba(94,234,212,0.08) var(--vol) 100%);
      box-shadow: 0 0 46px rgba(94,234,212,0.16), inset 0 0 36px rgba(0,0,0,0.5);
      border: 1px solid rgba(94,234,212,0.25);
    }

    .volume-ring::before {
      content: "";
      position: absolute;
      inset: 18px;
      border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.08);
      background:
        radial-gradient(circle at 50% 30%, rgba(255,255,255,0.08), transparent 25%),
        linear-gradient(180deg, rgba(12,22,35,0.95), rgba(3,8,14,0.98));
      box-shadow: inset 0 0 40px rgba(94,234,212,0.07);
    }

    .volume-readout {
      position: relative;
      z-index: 1;
      text-align: center;
    }

    .volume-readout .num {
      display: block;
      font-size: clamp(54px, 8vw, 86px);
      line-height: 0.9;
      font-weight: 900;
      letter-spacing: -0.08em;
      color: #f3feff;
      text-shadow: 0 0 24px rgba(94,234,212,0.35);
    }

    .volume-readout .unit {
      display: block;
      margin-top: 6px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.22em;
      font-size: 11px;
    }

    .volume-controls {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 16px;
    }

    .round-btn {
      border-radius: 24px;
      min-height: 76px;
      font-size: 34px;
      font-weight: 900;
    }

    .slider-wrap {
      margin-top: 16px;
      border: 1px solid rgba(94,234,212,0.15);
      background: rgba(2,9,17,0.45);
      border-radius: 20px;
      padding: 15px;
    }

    input[type="range"] {
      width: 100%;
      accent-color: var(--cyan);
    }

    .power-grid, .transport-grid, .tuner-grid, .utility-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .transport-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .utility-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 12px; }

    .cmd-btn, .ghost-btn, .danger-btn, .power-btn {
      min-height: 50px;
      border-radius: 17px;
      padding: 12px 12px;
      font-weight: 800;
      letter-spacing: 0.05em;
    }

    .power-btn.on { border-color: rgba(134,239,172,0.55); }
    .power-btn.off, .danger-btn { border-color: rgba(251,113,133,0.45); }
    .ghost-btn { color: var(--muted); }

    .field-row {
      display: flex;
      gap: 10px;
      align-items: center;
      margin-top: 12px;
    }

    .field-row input {
      flex: 1;
      min-width: 0;
      border: 1px solid rgba(94,234,212,0.22);
      border-radius: 16px;
      background: rgba(2, 8, 15, 0.82);
      color: var(--text);
      padding: 13px 14px;
      outline: none;
    }

    .field-row input:focus { border-color: rgba(94,234,212,0.55); box-shadow: 0 0 20px rgba(34,211,238,0.12); }

    .log {
      max-height: 190px;
      overflow: auto;
      border-radius: 18px;
      border: 1px solid rgba(94,234,212,0.13);
      background: rgba(1, 6, 11, 0.72);
      padding: 12px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 12px;
      color: #b7f7ff;
      white-space: pre-wrap;
    }

    .note {
      color: var(--muted);
      line-height: 1.45;
      font-size: 13px;
    }

    .modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      display: grid;
      place-items: center;
      padding: 20px;
      background: rgba(0, 3, 8, 0.78);
      backdrop-filter: blur(16px);
    }

    .modal-card {
      width: min(620px, 100%);
      border-radius: 30px;
      border: 1px solid rgba(94,234,212,0.28);
      background:
        radial-gradient(circle at 50% 0%, rgba(94,234,212,0.15), transparent 38%),
        linear-gradient(180deg, rgba(13,24,39,0.96), rgba(4,9,17,0.98));
      box-shadow: 0 0 60px rgba(94,234,212,0.18), 0 28px 80px rgba(0,0,0,0.62);
      padding: 28px;
      position: relative;
      overflow: hidden;
    }

    .modal-card h2 {
      margin: 0 0 10px;
      font-size: clamp(26px, 4vw, 42px);
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .modal-card p {
      color: var(--muted);
      line-height: 1.55;
      margin: 0 0 18px;
    }

    .connect-form {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
    }

    .connect-form input {
      width: 100%;
      border: 1px solid rgba(94,234,212,0.28);
      background: rgba(1,6,11,0.85);
      color: var(--text);
      border-radius: 18px;
      padding: 16px 15px;
      outline: none;
      font-size: 18px;
      letter-spacing: 0.03em;
    }

    .connect-form button, .primary-btn {
      border: 1px solid rgba(94,234,212,0.55);
      background: linear-gradient(180deg, rgba(94,234,212,0.28), rgba(34,211,238,0.12));
      color: var(--text);
      border-radius: 18px;
      padding: 14px 18px;
      cursor: pointer;
      font-weight: 900;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .error-box {
      display: none;
      margin-top: 14px;
      border: 1px solid rgba(251,113,133,0.35);
      background: rgba(251,113,133,0.09);
      color: #fecdd3;
      padding: 12px 14px;
      border-radius: 16px;
    }

    .switch-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 12px;
      color: var(--muted);
      font-size: 13px;
    }

    .switch-row input { accent-color: var(--cyan); }

    .mt { margin-top: 18px; }
    .mb { margin-bottom: 18px; }

    @media (max-width: 1160px) {
      .deck { grid-template-columns: 1fr; }
      .display-panel { min-height: auto; }
    }

    @media (max-width: 760px) {
      .topbar { align-items: flex-start; flex-direction: column; }
      .top-actions { justify-content: flex-start; width: 100%; }
      .source-grid, .power-grid, .transport-grid, .tuner-grid, .utility-grid { grid-template-columns: 1fr 1fr; }
      .state-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .connect-form { grid-template-columns: 1fr; }
      .panel-inner { padding: 16px; }
    }

    @media (max-width: 460px) {
      .source-grid, .power-grid, .transport-grid, .tuner-grid, .utility-grid, .volume-controls { grid-template-columns: 1fr; }
      .state-row { grid-template-columns: 1fr; }
      .wrap { width: min(100vw - 18px, 1440px); }
    }
  </style>
</head>
<body>
  <div class="modal" id="ipModal">
    <div class="modal-card">
      <div class="logo mb"></div>
      <h2>Link the CEOL</h2>
      <p>Enter the Denon IP address or local URL. Commands are sent directly from this browser to the Denon; the PHP server does not connect to your LAN.</p>
      <div class="connect-form">
        <input type="text" id="denonIpInput" placeholder="192.168.1.45 or http://192.168.1.45" value="" autocomplete="off">
        <button type="button" onclick="saveIp()">Connect</button>
      </div>
      <div class="error-box" id="ipError"></div>
      <p class="note mt">On the Denon, enable <b>Network Control</b>. Browser-direct mode uses the Denon HTTP goform interface. If this page is HTTPS and the Denon is HTTP-only, your browser may block mixed-content/local-network requests; in that case open this page over HTTP on the local network or use a Denon HTTPS URL that your browser trusts.</p>
    </div>
  </div>

  <main class="wrap">
    <!-- MICRO_REMOTE_V7_START: icon-only mobile command center with mini display and synced volume chip -->
    <section class="mini-remote" data-version="micro-v7" aria-label="Simplified Denon remote">
      <div class="mini-volume-chip" id="miniVolumeNum" aria-label="Volume">--</div>
      <div class="mini-row">
        <button class="mini-btn" id="miniPower" type="button" aria-label="Toggle power" title="Toggle power" onclick="togglePower()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v8"></path><path d="M7.05 6.6a8 8 0 1 0 9.9 0"></path></svg>
        </button>
        <button class="mini-btn mini-source" id="miniServer" type="button" aria-label="Server source" title="Server" onclick="cmd('SISERVER')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="5" rx="1.6"></rect><rect x="5" y="10.5" width="14" height="5" rx="1.6"></rect><rect x="5" y="17" width="14" height="3" rx="1.4"></rect><path d="M8 6.5h.01M8 13h.01"></path><path d="M12 20v1.2"></path></svg>
        </button>
        <button class="mini-btn mini-source" id="miniBt" type="button" aria-label="Bluetooth source" title="Bluetooth" onclick="cmd('SIBLUETOOTH')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6l8 6-8 6V6z"></path><path d="M8 6l8-3v18l-8-3"></path><path d="M4 8l4 4-4 4"></path></svg>
        </button>
      </div>

      <div class="mini-display" aria-label="Mini display rows 1, 2, 5 and 6">
        <div class="mini-display-lines" id="miniDisplayLines">
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
          <div class="mini-display-line empty">····················</div>
        </div>
      </div>

      <div class="mini-pad" aria-label="Volume and navigation pad">
        <button class="mini-btn mini-up" id="miniVolUp" type="button" aria-label="Volume up" title="Volume up">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        </button>
        <button class="mini-btn mini-left" id="miniPrev" type="button" aria-label="Previous" title="Previous">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 6l-6 6 6 6"></path><path d="M19 6l-6 6 6 6"></path></svg>
        </button>
        <button class="mini-btn mini-enter" type="button" aria-label="Enter" title="Enter" onclick="cmd('NS94')">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6.5"></circle><circle cx="12" cy="12" r="1"></circle></svg>
        </button>
        <button class="mini-btn mini-right" id="miniNext" type="button" aria-label="Next" title="Next">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6l6 6-6 6"></path><path d="M13 6l6 6-6 6"></path></svg>
        </button>
        <button class="mini-btn mini-down" id="miniVolDown" type="button" aria-label="Volume down" title="Volume down">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"></path></svg>
        </button>
      </div>

      <div class="mini-row">
        <button class="mini-btn" id="miniMute" type="button" aria-label="Mute" title="Mute" onclick="toggleMute()" data-muted="0">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M16.5 9.5a4 4 0 0 1 0 5"></path><path d="M19 7a7 7 0 0 1 0 10"></path></svg>
        </button>
      </div>
    </section>

    <!-- MICRO_REMOTE_V7_END -->

    <div class="topbar">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <h1><?= h(APP_TITLE) ?></h1>
          <div class="subtitle">Denon CEOL / RCD-N9 Browser-Direct LAN Remote</div>
        </div>
      </div>
      <div class="top-actions">
        <div class="status-pill"><span class="dot" id="connDot"></span><span id="connText">Offline</span></div>
        <div class="ip-pill">LAN <b id="ipText">not set</b></div>
        <button class="ghost-btn" type="button" onclick="showIpModal()">Change IP</button>
      </div>
    </div>

    <div class="deck">
      <section class="panel">
        <div class="panel-inner">
          <div class="section-title"><span>Input Matrix</span><span class="panel-code">SI BUS</span></div>
          <div class="source-grid" id="sourceGrid">
            <?php foreach ($sources as $src): ?>
              <button type="button" class="source-btn" data-command="<?= h($src[0]) ?>" onclick="cmd('<?= h($src[0]) ?>')">
                <strong><?= h($src[1]) ?></strong>
                <span><?= h($src[2]) ?></span>
              </button>
            <?php endforeach; ?>
          </div>

          <div class="mt">
            <div class="section-title"><span>Power Core</span><span class="panel-code">PW / MU</span></div>
            <div class="power-grid">
              <button class="power-btn on" type="button" onclick="cmd('PWON')">Power On</button>
              <button class="power-btn off" type="button" onclick="cmd('PWSTANDBY')">Standby</button>
              <button class="cmd-btn" type="button" onclick="setMuteFromMain(true)">Mute</button>
              <button class="cmd-btn" type="button" onclick="setMuteFromMain(false)">Unmute</button>
            </div>
          </div>

          <div class="switch-row">
            <span>Browser-direct mode: JavaScript → Denon HTTP API (no PHP relay)</span>
          </div>
        </div>
      </section>

      <section class="panel display-panel">
        <div class="panel-inner">
          <div class="section-title"><span>Local Command State</span><span class="panel-code">BROWSER DIRECT</span></div>
          <div class="oled">
            <div class="display-lines" id="displayLines">
              <?php for ($i = 0; $i < 9; $i++): ?>
                <div class="display-line empty">····················</div>
              <?php endfor; ?>
            </div>
          </div>
          <p class="note mt">This panel reflects commands sent from this page. A normal cross-origin browser request can send commands to the Denon, but usually cannot read the Denon reply unless the device explicitly allows CORS.</p>

          <div class="state-row">
            <div class="state-card"><small>Power</small><b id="powerText">Unknown</b></div>
            <div class="state-card"><small>Source</small><b id="sourceText">Unknown</b></div>
            <div class="state-card"><small>Mute</small><b id="muteText">Unknown</b></div>
            <div class="state-card"><small>Tuner</small><b id="tunerText">—</b></div>
          </div>

          <div class="mt">
            <div class="section-title"><span>Navigation / Transport</span><span class="panel-code">NS BUS</span></div>
            <div class="transport-grid">
              <button class="cmd-btn" type="button" onclick="cmd('NS90')">▲ Up</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS94')">Enter</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS91')">▼ Down</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS92')">◀ Left</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9A')">Play</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS93')">Right ▶</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9E')">Prev</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9B')">Pause</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9D')">Next</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9X')">Page +</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9C')">Stop</button>
              <button class="cmd-btn" type="button" onclick="cmd('NS9Y')">Page -</button>
            </div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-inner">
          <div class="section-title"><span>Amplitude Drive</span><span class="panel-code">MV 00-60</span></div>
          <div class="volume-stage">
            <div class="volume-ring" id="volumeRing">
              <div class="volume-readout">
                <span class="num" id="volumeNum">--</span>
                <span class="unit">Volume</span>
              </div>
            </div>
          </div>

          <div class="volume-controls">
            <button class="round-btn" type="button" id="volDown">−</button>
            <button class="round-btn" type="button" id="volUp">+</button>
          </div>

          <div class="slider-wrap">
            <input type="range" min="0" max="60" value="0" id="volumeSlider">
          </div>

          <div class="mt">
            <div class="section-title"><span>Tuner / Channels</span><span class="panel-code">TF / FV</span></div>
            <div class="tuner-grid">
              <button class="cmd-btn" type="button" onclick="cmd('TFANDOWN')">Tune / Ch −</button>
              <button class="cmd-btn" type="button" onclick="cmd('TFANUP')">Tune / Ch +</button>
              <button class="cmd-btn" type="button" onclick="cmd('TMANFM')">FM Band</button>
              <button class="cmd-btn" type="button" onclick="cmd('TMANAM')">AM Band</button>
            </div>
            <div class="field-row">
              <input type="number" min="1" max="50" value="1" id="favoriteNo" placeholder="Favorite 1-50">
              <button class="cmd-btn" type="button" onclick="favoriteGo()">FV</button>
            </div>
          </div>

          <div class="mt">
            <div class="section-title"><span>Diagnostics</span><span class="panel-code">RAW</span></div>
            <div class="utility-grid">
              <button class="ghost-btn" type="button" onclick="refreshStatus(true)">Refresh</button>
              <button class="ghost-btn" type="button" onclick="cmd('NSE')">Display</button>
              <button class="ghost-btn" type="button" onclick="cmd('NSINF?')">Network</button>
              <button class="ghost-btn" type="button" onclick="testConnection()">Test</button>
            </div>
            <div class="field-row">
              <input type="text" id="manualCommand" placeholder="Allowed command, e.g. MV20 or SIIRADIO">
              <button class="cmd-btn" type="button" onclick="sendManual()">Send</button>
            </div>
            <div class="log mt" id="logBox">Boot sequence ready.</div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <script>
    let denonBaseUrl = '';
    let busy = false;
    let lastKnownVolume = null;
    let lastKnownPower = 'unknown';
    let lastKnownMute = null;
    let lastKnownSource = '';

    const fixedCommands = new Set([
      'PWON', 'PWSTANDBY', 'PW?',
      'MVUP', 'MVDOWN', 'MV?',
      'MUON', 'MUOFF', 'MU?',
      'SI?',
      'SICD', 'SITUNER', 'SIFM', 'SIAM', 'SIIRADIO', 'SISERVER', 'SIUSB',
      'SIBLUETOOTH', 'SIBT', 'SIDIGITALIN1', 'SIDIGITALIN2', 'SIANALOGIN',
      'TFANUP', 'TFANDOWN', 'TFAN?', 'TFANNAME?',
      'TMANFM', 'TMANAM', 'TMANAUTO', 'TMANMANUAL', 'TM?',
      'NSA', 'NSE', 'NSINF?', 'SSFMT?',
      'NS90', 'NS91', 'NS92', 'NS93', 'NS94',
      'NS9A', 'NS9B', 'NS9C', 'NS9D', 'NS9E', 'NS9X', 'NS9Y'
    ]);

    function qs(id) {
      return document.getElementById(id);
    }

    function setLog(text, append = true) {
      const box = qs('logBox');
      const stamp = new Date().toLocaleTimeString();
      const line = '[' + stamp + '] ' + text;
      box.textContent = append ? (line + '\n' + box.textContent).slice(0, 6000) : line;
    }

    function normalizeCommand(command) {
      return String(command || '').trim().toUpperCase();
    }

    function isAllowedCommand(command) {
      command = normalizeCommand(command);
      if (fixedCommands.has(command)) return true;
      if (/^MV([0-5][0-9]|60)$/.test(command)) return true;
      if (/^FV(0[1-9]|[1-4][0-9]|50)$/.test(command)) return true;
      if (/^TFAN[0-9]{6}$/.test(command)) return true;
      return false;
    }

    function isPrivateIpv4(ip) {
      const p = ip.split('.').map(Number);
      if (p.length !== 4 || p.some(n => !Number.isInteger(n) || n < 0 || n > 255)) return false;
      if (p[0] === 10) return true;
      if (p[0] === 172 && p[1] >= 16 && p[1] <= 31) return true;
      if (p[0] === 192 && p[1] === 168) return true;
      if (p[0] === 169 && p[1] === 254) return true;
      if (p[0] === 127) return true;
      return false;
    }

    function normalizeTarget(raw) {
      let value = String(raw || '').trim();
      if (!value) throw new Error('Enter the Denon IP address.');
      if (!/^https?:\/\//i.test(value)) value = 'http://' + value;

      let url;
      try {
        url = new URL(value);
      } catch (e) {
        throw new Error('Invalid Denon address. Example: 192.168.1.45');
      }

      if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Only HTTP or HTTPS can be used in browser-direct mode.');
      }
      if (!isPrivateIpv4(url.hostname)) {
        throw new Error('Use a private LAN IPv4 address, for example 192.168.1.45.');
      }

      return {
        baseUrl: url.protocol + '//' + url.host,
        ip: url.hostname
      };
    }

    function commandPath(command) {
      command = normalizeCommand(command);

      if (command === 'PWON') {
        return '/goform/formiPhoneAppPower.xml?1+PowerOn';
      }
      if (command === 'PWSTANDBY') {
        return '/goform/formiPhoneAppPower.xml?1+PowerStandby';
      }
      if (command === 'MUON') {
        return '/goform/formiPhoneAppMute.xml?1+MuteOn';
      }
      if (command === 'MUOFF') {
        return '/goform/formiPhoneAppMute.xml?1+MuteOff';
      }

      return '/goform/formiPhoneAppDirect.xml?' + encodeURIComponent(command);
    }

    async function directSend(command) {
      command = normalizeCommand(command);
      if (!denonBaseUrl) throw new Error('No Denon IP configured.');
      if (!isAllowedCommand(command)) throw new Error('Command not allowed: ' + command);

      const url = denonBaseUrl + commandPath(command);

      // mode:no-cors is intentional. The browser may send the cross-origin GET even when
      // JavaScript is not permitted to inspect the Denon's response.
      await fetch(url, {
        method: 'GET',
        mode: 'no-cors',
        cache: 'no-store',
        credentials: 'omit',
        referrerPolicy: 'no-referrer'
      });

      return url;
    }

    function showIpModal() {
      qs('ipModal').style.display = 'grid';
      qs('denonIpInput').focus();
    }

    function hideIpModal() {
      qs('ipModal').style.display = 'none';
    }

    async function saveIp() {
      qs('ipError').style.display = 'none';

      let target;
      try {
        target = normalizeTarget(qs('denonIpInput').value);
      } catch (err) {
        qs('ipError').textContent = err.message;
        qs('ipError').style.display = 'block';
        return;
      }

      denonBaseUrl = target.baseUrl;
      localStorage.setItem('denon_ceol_base_url', denonBaseUrl);
      localStorage.setItem('denon_ceol_ip', target.ip);
      qs('ipText').textContent = denonBaseUrl.replace(/^https?:\/\//i, '');
      hideIpModal();
      setConnection(true, 'Direct mode');
      setLog('Browser-direct target: ' + denonBaseUrl);

      if (location.protocol === 'https:' && denonBaseUrl.startsWith('http://')) {
        setLog('WARNING: HTTPS page → HTTP Denon may be blocked by mixed-content policy.');
      }

      await testConnection();
    }

    async function testConnection() {
      if (!denonBaseUrl) {
        showIpModal();
        return false;
      }

      setConnection(false, 'Sending test');
      try {
        await directSend('PW?');
        setConnection(true, 'Direct mode');
        setLog('PW? dispatched directly by browser. Reply is intentionally not read cross-origin.');
        return true;
      } catch (err) {
        setConnection(false, 'Browser blocked');
        setLog('TEST ERROR: ' + err.message);
        return false;
      }
    }

    function setConnection(online, text) {
      qs('connDot').classList.toggle('online', !!online);
      qs('connText').textContent = text || (online ? 'Direct mode' : 'Blocked');
    }

    const miniMuteLiveIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M16.5 9.5a4 4 0 0 1 0 5"></path><path d="M19 7a7 7 0 0 1 0 10"></path></svg>';
    const miniMuteOffIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4z"></path><path d="M17 9l4 6"></path><path d="M21 9l-4 6"></path></svg>';

    function setMiniMuteVisual(isMuted) {
      const btn = qs('miniMute');
      btn.classList.toggle('muted-active', isMuted === true);
      btn.dataset.muted = isMuted === true ? '1' : '0';
      btn.innerHTML = isMuted === true ? miniMuteOffIcon : miniMuteLiveIcon;
      btn.setAttribute('aria-label', isMuted === true ? 'Unmute' : 'Mute');
      btn.title = isMuted === true ? 'Unmute' : 'Mute';
    }

    function togglePower() {
      cmd(lastKnownPower === 'on' ? 'PWSTANDBY' : 'PWON');
    }

    function toggleMute() {
      const targetMuted = lastKnownMute === true ? false : true;
      setMuteVisualEverywhere(targetMuted);
      cmd(targetMuted ? 'MUON' : 'MUOFF');
    }

    function setMuteVisualEverywhere(isMuted) {
      lastKnownMute = isMuted;
      setMiniMuteVisual(isMuted);
      qs('muteText').textContent = isMuted === true ? 'Muted' : 'Live';
    }

    function setMuteFromMain(isMuted) {
      setMuteVisualEverywhere(isMuted);
      cmd(isMuted ? 'MUON' : 'MUOFF');
    }

    function setVolumeVisualEverywhere(vol, percent = null) {
      if (vol === null || vol === undefined || Number.isNaN(Number(vol))) return;

      const n = Math.max(0, Math.min(60, parseInt(vol, 10)));
      lastKnownVolume = n;
      const padded = String(n).padStart(2, '0');

      qs('volumeNum').textContent = padded;
      qs('miniVolumeNum').textContent = padded;
      qs('volumeSlider').value = n;

      const ringPercent = percent !== null && percent !== undefined
        ? percent
        : Math.max(0, Math.min(100, Math.round((n / 60) * 100)));
      qs('volumeRing').style.setProperty('--vol', ringPercent + '%');
    }

    function nudgeVolumeVisual(delta) {
      if (lastKnownVolume === null || lastKnownVolume === undefined || Number.isNaN(Number(lastKnownVolume))) return;
      setVolumeVisualEverywhere(Number(lastKnownVolume) + delta);
    }

    function sourceLabelForCommand(command) {
      const btn = document.querySelector('.source-btn[data-command="' + CSS.escape(command) + '"]');
      if (btn) {
        const strong = btn.querySelector('strong');
        if (strong) return strong.textContent.trim();
      }
      return command.startsWith('SI') ? command.substring(2) : command;
    }

    function updateLocalDisplay() {
      const rows = [
        'BROWSER DIRECT',
        lastKnownSource ? sourceLabelForCommand(lastKnownSource) : 'SOURCE —',
        '',
        '',
        'POWER ' + (lastKnownPower === 'unknown' ? '—' : lastKnownPower.toUpperCase()),
        'VOL ' + (lastKnownVolume === null ? '--' : String(lastKnownVolume).padStart(2, '0')) + '  ' + (lastKnownMute === true ? 'MUTED' : 'LIVE'),
        '',
        '',
        ''
      ];

      const holder = qs('displayLines');
      holder.innerHTML = '';
      for (let i = 0; i < 9; i++) {
        const div = document.createElement('div');
        const text = rows[i] || '';
        div.className = 'display-line' + (text === '' ? ' empty' : '');
        div.textContent = text || '····················';
        holder.appendChild(div);
      }

      renderMiniDisplay(rows);
    }

    function applyOptimisticState(command) {
      command = normalizeCommand(command);

      if (command === 'PWON') lastKnownPower = 'on';
      if (command === 'PWSTANDBY') lastKnownPower = 'standby';
      if (command === 'MUON') lastKnownMute = true;
      if (command === 'MUOFF') lastKnownMute = false;
      if (command === 'MVUP') nudgeVolumeVisual(1);
      if (command === 'MVDOWN') nudgeVolumeVisual(-1);

      const mv = command.match(/^MV([0-9]{2})$/);
      if (mv) setVolumeVisualEverywhere(parseInt(mv[1], 10));

      if (command.startsWith('SI') && command !== 'SI?') {
        lastKnownSource = command;
      }

      qs('powerText').textContent = lastKnownPower === 'on' ? 'On' : (lastKnownPower === 'standby' ? 'Standby' : 'Unknown');
      qs('muteText').textContent = lastKnownMute === true ? 'Muted' : (lastKnownMute === false ? 'Live' : 'Unknown');
      qs('sourceText').textContent = lastKnownSource ? sourceLabelForCommand(lastKnownSource) : 'Unknown';
      qs('miniPower').classList.toggle('power-active', lastKnownPower === 'on');
      setMiniMuteVisual(lastKnownMute === true);
      qs('miniServer').classList.toggle('active', lastKnownSource === 'SISERVER');
      qs('miniBt').classList.toggle('active', lastKnownSource === 'SIBLUETOOTH' || lastKnownSource === 'SIBT');

      document.querySelectorAll('.source-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-command') === lastKnownSource);
      });

      updateLocalDisplay();
      saveLocalState();
    }

    function saveLocalState() {
      localStorage.setItem('denon_ceol_local_state', JSON.stringify({
        volume: lastKnownVolume,
        power: lastKnownPower,
        mute: lastKnownMute,
        source: lastKnownSource
      }));
    }

    function restoreLocalState() {
      try {
        const state = JSON.parse(localStorage.getItem('denon_ceol_local_state') || '{}');
        if (state.volume !== null && state.volume !== undefined) setVolumeVisualEverywhere(state.volume);
        if (state.power) lastKnownPower = state.power;
        if (state.mute === true || state.mute === false) lastKnownMute = state.mute;
        if (state.source) lastKnownSource = state.source;
      } catch (e) {
        // Ignore malformed old local state.
      }
      applyOptimisticState('');
    }

    async function cmd(command) {
      command = normalizeCommand(command);
      if (!command) return;
      if (!denonBaseUrl) {
        showIpModal();
        return;
      }
      if (!isAllowedCommand(command)) {
        setLog('BLOCKED ' + command);
        return;
      }

      setLog('TX browser → Denon: ' + command);
      applyOptimisticState(command);

      try {
        await directSend(command);
        setConnection(true, 'Direct mode');
        setLog('SENT ' + command);
      } catch (err) {
        setConnection(false, 'Browser blocked');
        setLog('ERROR ' + command + ': ' + err.message);
      }
    }

    async function refreshStatus(manual = false) {
      if (!denonBaseUrl) {
        if (manual) showIpModal();
        return;
      }

      if (manual) {
        setLog('Status readback is not available in generic browser-direct mode because the Denon reply is cross-origin. Sending PW? as a transport probe.');
        await testConnection();
      }
    }

    function renderMiniDisplay(lines) {
      const holder = qs('miniDisplayLines');
      const indexes = [0, 1, 4, 5];
      holder.innerHTML = '';

      indexes.forEach(idx => {
        const div = document.createElement('div');
        const text = ((lines && lines[idx]) ? lines[idx] : '').trim();
        div.className = 'mini-display-line' + (text === '' ? ' empty' : '');
        div.textContent = text !== '' ? text : '····················';
        holder.appendChild(div);
      });
    }

    function bindHoldButton(id, command) {
      const btn = qs(id);
      let timer = null;

      const start = (ev) => {
        ev.preventDefault();
        cmd(command);
        timer = setInterval(() => cmd(command), 320);
      };

      const stop = () => {
        if (timer) clearInterval(timer);
        timer = null;
      };

      btn.addEventListener('pointerdown', start);
      window.addEventListener('pointerup', stop);
      window.addEventListener('pointercancel', stop);
      btn.addEventListener('mouseleave', stop);
    }

    async function favoriteGo() {
      const val = parseInt(qs('favoriteNo').value, 10);
      const value = Math.max(1, Math.min(50, Number.isFinite(val) ? val : 1));
      qs('favoriteNo').value = value;
      await cmd('FV' + String(value).padStart(2, '0'));
    }

    async function setVolume(value) {
      const v = Math.max(0, Math.min(60, parseInt(value, 10) || 0));
      setVolumeVisualEverywhere(v);
      await cmd('MV' + String(v).padStart(2, '0'));
    }

    function sendManual() {
      const command = qs('manualCommand').value.trim().toUpperCase();
      if (!command) return;
      cmd(command);
    }

    qs('volumeSlider').addEventListener('change', function () {
      setVolume(this.value);
    });

    qs('manualCommand').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') sendManual();
    });

    qs('denonIpInput').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') saveIp();
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(ev.target.tagName)) return;
      if (ev.key === '+') cmd('MVUP');
      if (ev.key === '-') cmd('MVDOWN');
      if (ev.key.toLowerCase() === 'm') toggleMute();
      if (ev.key.toLowerCase() === 'r') refreshStatus(true);
    });

    bindHoldButton('volUp', 'MVUP');
    bindHoldButton('volDown', 'MVDOWN');
    bindHoldButton('miniVolUp', 'MVUP');
    bindHoldButton('miniVolDown', 'MVDOWN');
    bindHoldButton('miniPrev', 'NS9D');
    bindHoldButton('miniNext', 'NS9E');

    (function boot() {
      const savedBase = localStorage.getItem('denon_ceol_base_url');
      const savedIp = localStorage.getItem('denon_ceol_ip');

      if (savedBase) {
        denonBaseUrl = savedBase;
        qs('denonIpInput').value = savedBase;
        qs('ipText').textContent = savedBase.replace(/^https?:\/\//i, '');
        hideIpModal();
        setConnection(true, 'Direct mode');
      } else if (savedIp) {
        denonBaseUrl = 'http://' + savedIp;
        qs('denonIpInput').value = savedIp;
        qs('ipText').textContent = savedIp;
        hideIpModal();
        setConnection(true, 'Direct mode');
      } else {
        showIpModal();
      }

      restoreLocalState();

      if (denonBaseUrl && location.protocol === 'https:' && denonBaseUrl.startsWith('http://')) {
        setLog('WARNING: This page is HTTPS but the Denon target is HTTP. The browser may block the request.');
      }
    })();
  </script>
</body>
</html>
