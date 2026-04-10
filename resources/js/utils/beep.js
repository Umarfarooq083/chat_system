let audioContext = null
let unlocked = false
let unlocking = false
let lastBeepAt = 0

const getAudioContext = () => {
  if (audioContext) return audioContext
  const Ctx = window.AudioContext || window.webkitAudioContext
  if (!Ctx) return null
  audioContext = new Ctx()
  return audioContext
}

export const unlockAudio = async () => {
  if (unlocked || unlocking) return unlocked
  unlocking = true
  try {
    const ctx = getAudioContext()
    if (!ctx) return false
    if (ctx.state === 'suspended') {
      await ctx.resume()
    }
    unlocked = ctx.state === 'running'
    return unlocked
  } catch (e) {
    return false
  } finally {
    unlocking = false
  }
}

// Browsers block audio until the user interacts with the page.
// Call this once on a page to unlock audio on first user gesture.
export const setupAudioUnlock = () => {
  if (unlocked) return

  const handler = async () => {
    await unlockAudio()
  }

  window.addEventListener('pointerdown', handler, { once: true })
  window.addEventListener('keydown', handler, { once: true })
}

export const beep = async ({
  // Default sound: a short 2-tone "chime" (nicer than a single beep)
  pattern = [
    { frequency: 784, durationMs: 80 },  // G5
    { frequency: 988, durationMs: 110 }  // B5
  ],
  volume = 0.05,
  minIntervalMs = 500,
  type = 'triangle'
} = {}) => {
  const now = Date.now()
  if (now - lastBeepAt < minIntervalMs) return
  lastBeepAt = now

  const ok = await unlockAudio()
  if (!ok) return

  const ctx = getAudioContext()
  if (!ctx) return

  const baseTime = ctx.currentTime
  const attack = 0.008
  const release = 0.05
  const gap = 0.02

  let t = baseTime
  const tones = Array.isArray(pattern) && pattern.length
    ? pattern
    : [{ frequency: 880, durationMs: 140 }]

  for (const tone of tones) {
    const frequency = Number(tone?.frequency) || 880
    const durationMs = Number(tone?.durationMs) || 120
    const dur = Math.max(0.03, durationMs / 1000)

    const osc = ctx.createOscillator()
    const gain = ctx.createGain()

    osc.type = type
    osc.frequency.value = frequency

    gain.gain.setValueAtTime(0.0001, t)
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, volume), t + attack)
    gain.gain.exponentialRampToValueAtTime(0.0001, Math.max(t + attack + release, t + dur))

    osc.connect(gain)
    gain.connect(ctx.destination)

    osc.start(t)
    osc.stop(Math.max(t + dur, t + attack + release))

    t = t + dur + gap
  }
}
