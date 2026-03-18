'use strict';

const Audio = (() => {
    let ctx = null;

    function getContext() {
        if (!ctx) {
            ctx = new AudioContext();
        }
        return ctx;
    }

    function beep(freq = 880, duration = 0.15, volume = 0.6) {
        const audioCtx = getContext();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(volume, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
        osc.start();
        osc.stop(audioCtx.currentTime + duration);
    }

    function warning() {
        beep(660, 0.2, 0.5);
    }

    function transition() {
        beep(880, 0.15, 0.6);
        setTimeout(() => beep(880, 0.15, 0.6), 200);
    }

    function complete() {
        beep(660, 0.15, 0.5);
        setTimeout(() => beep(880, 0.15, 0.5), 200);
        setTimeout(() => beep(1100, 0.2, 0.6), 400);
    }

    function speak(text) {
        if (!window.speechSynthesis || !text) return;
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;
        window.speechSynthesis.speak(utterance);
    }

    return { beep, warning, transition, complete, speak };
})();
