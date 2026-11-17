<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ToggleSwitch from '@core/components/ToggleSwitch.vue'
import { features, setFeature } from '../../features.js'

const { t } = useI18n()

// Settings > Features: the optional features. Each switch also adds/removes
// that feature's own page under Features in the rail (the product's
// railCategories() reads the same features.js state).
const featureSaving = ref(null)
const featureError = ref(null)

async function toggleFeature(name, enabled) {
  if (featureSaving.value) return
  featureSaving.value = name
  featureError.value = null
  try {
    await setFeature(name, enabled)
  } catch (err) {
    featureError.value = err.message
  } finally {
    featureSaving.value = null
  }
}
</script>

<template>
  <div class="settings-page">
    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.features.title') }}</h2></div>

      <div v-if="features.airshowInstalled" class="feature">
        <div class="feature-text">
          <span class="feature-name">{{ t('settingsLayout.screenMirroring') }}</span>
          <span class="hint">{{ t('settings.features.screenMirroringHint') }}</span>
        </div>
        <ToggleSwitch
          :model-value="features.airshow"
          :disabled="!features.loaded || featureSaving === 'airshow'"
          @update:model-value="toggleFeature('airshow', $event)"
        />
      </div>

      <div class="feature">
        <div class="feature-text">
          <span class="feature-name">{{ t('settingsLayout.revelationPeering') }}</span>
          <span class="hint">{{ t('settings.features.revelationHint') }}</span>
        </div>
        <ToggleSwitch
          :model-value="features.revelation"
          :disabled="!features.loaded || featureSaving === 'revelation'"
          @update:model-value="toggleFeature('revelation', $event)"
        />
      </div>

      <div class="feature">
        <div class="feature-text">
          <span class="feature-name">{{ t('settingsLayout.videoReceiver') }}</span>
          <span class="hint">{{ t('settings.features.srtSinkHint') }}</span>
          <span v-if="features.srtSink && !features.srtSinkServerAllows" class="pill warn">
            {{ t('settings.srtSink.serverDisabled') }}
          </span>
        </div>
        <ToggleSwitch
          :model-value="features.srtSink"
          :disabled="!features.loaded || featureSaving === 'srtSink'"
          @update:model-value="toggleFeature('srtSink', $event)"
        />
      </div>

      <p v-if="featureError" class="pill warn note">{{ featureError }}</p>
    </section>
  </div>
</template>

<style scoped>
.hint {
  color: var(--text-dim);
  margin: 0;
  font-size: 0.9rem;
}
.note {
  margin: 0.6rem 0 0;
}
.feature {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  padding: 0.6rem 0;
}
.feature + .feature {
  border-top: var(--line) solid var(--border);
}
.feature-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
}
.feature-name {
  font-weight: 600;
}
</style>
