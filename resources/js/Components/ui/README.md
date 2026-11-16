# Shared UI patterns

Reuse interaction behavior and layout boundaries when pages have the same job. Pages retain their own permissions, routes, field state, and business rules.

## Product selection

`ProductPicker` takes `v-model` (a product id), `products`, and an optional `id` for an external label. It searches product and variant names, SKUs and barcodes locally, prioritizes exact identifiers, and renders at most 40 matches with a refinement prompt. Arrow keys move through results; Enter selects; Escape restores the current selection; Tab closes the list. Editing clears the selected id, so unmatched text cannot silently submit the previous product. Required pickers also use native constraint validation.

The component emits `change` after updating the model. Callers retain variant selection, prices, supplier defaults, quantities, and scanning. `required`, `disabled`, `invalid`, and `describedby` connect the field to its form. Each instance owns unique list and option ids.

Pass `stockLabel` only when the quantity's scope is known. Without `stockByProduct`, the optional stock label describes the supplied product stock (for example, all locations). A `stockByProduct` map overrides those values. Transfer forms load availability for the selected authorized source; loading or failed requests clear old quantities. Variant quantities remain in the caller's variant selector. Search matches on a variant identify its product and still require an explicit variant choice.

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
