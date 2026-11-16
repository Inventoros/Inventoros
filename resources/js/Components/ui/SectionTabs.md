# Local page sections

Use `SectionTabs` and `SectionPanel` for views that switch content within the same page. Keep ordinary links for navigation to other URLs.

```vue
<SectionTabs v-model="active" :tabs="sections" :label="t('settings.organization.title')">
    <SectionPanel tab="general"><GeneralForm /></SectionPanel>
    <SectionPanel tab="regional"><RegionalForm /></SectionPanel>
</SectionTabs>
```

Each entry in `sections` has a unique string `key` and a translated `label`; `disabled` is optional. The page filters out unauthorized sections. Each entry must have a matching panel, including inactive entries, so the tab's `aria-controls` always refers to a real element.

The component owns selection semantics, unique IDs, roving keyboard focus, Left/Right with RTL support, and Home/End. Labels wrap onto another row when necessary; short labels still share one row. Every tab has a minimum 44px target. A removed or disabled selection falls back to the first available entry.

`SectionPanel` requires a surrounding `SectionTabs`. It keeps content mounted and hides inactive panels so forms retain their state. If a panel's expensive content should mount only when visited, keep that decision in the page, as `PluginTabs` does for plugin components.

Use the optional `#badge="{ tab }"` slot for a section's real count or status. The page owns the value and its meaning.
