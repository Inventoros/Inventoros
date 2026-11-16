# Shared UI patterns

Reuse interaction behavior and layout boundaries when pages have the same job. Pages retain their own permissions, routes, field state, and business rules.

## Empty lists and filtered results

`EmptyState` presents a feature's first-use state, or search recovery when `filtered` is true. Pass the **applied server filters**, so typing into an unsubmitted search does not change the result message.

```vue
<EmptyState
    :icon="Boxes"
    :title="t('products.noProductsFound')"
    :filtered="Boolean(filters?.search)"
    @clear="clearFilters"
>
    <template #actions>
        <Button v-if="canVisit('products.create')" as="Link" :href="route('products.create')">
            {{ t('products.addFirstProduct') }}
        </Button>
    </template>
</EmptyState>
```

`title` is required; `description` and `icon` are optional. The icon is decorative. When filtered, the component shows the translated no-results message and emits `clear` from its recovery button. The page must clear every applied filter in that handler. The first-use action slot stays hidden until the filtered state ends.

For handwritten tables, pass `:empty="!records.data.length"` to `TableViewport` and put the empty state in its `#empty` slot. This keeps the message and actions outside the horizontally scrolling table. The empty panel scrolls vertically when needed; the full-table control returns when records exist.

`DataTable` chooses this boundary from `rows.length` automatically. Its existing `#empty` slot remains supported, and an omitted slot uses the shared default message. Populated tables retain their existing scrolling, full-window view, and footer.
