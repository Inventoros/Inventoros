# Customer form extensions

Plugins can add fields to Customers/Create and Customers/Edit without replacing
the core form. Register components in `header`, `before-form`, `business-details`
or `after-form`. The last three receive the live Inertia `form` prop, including
its `processing` and `errors` state. A component writes its values to
`form.plugin_fields['my-plugin']`. Server component props cannot replace the live
form passed by the page. Customers/Show also has `business-details`, receiving
the current `customer` prop.

Use `customer_form_data($values, $customerOrNull, $request)` to supply namespaced
initial values. Register validation with
`customer_store_validation_rules($rules, $request)` and
`customer_update_validation_rules($rules, $customer, $request)`.

```php
add_filter('customer_store_validation_rules', fn ($rules) => $rules + [
    'plugin_fields.my-plugin.reference' => ['nullable', 'string', 'max:80'],
]);
add_filter('customer_store_data', function ($data, $request) {
    $data['metadata'] = ['my-plugin' => $data['plugin_fields']['my-plugin'] ?? []];
    return $data;
});
```

Add the equivalent update rules and a `customer_update_data($data, $customer,
$request)` filter. Preserve other namespaces when merging `metadata`, and do
not replace existing values on a partial update that omits your fields. These
validation and data filters apply to both web and REST customer writes. Only
validated extension fields reach data filters; the temporary `plugin_fields`
container is removed before model persistence. The customer remains bound to
the authorized organization. Existing customer domain hooks still fire normally.

Validate individual leaf fields rather than allowing the entire `plugin_fields`
array. Plugins remain responsible for authorization of their own fields, tenant
scoping, and safely serializing values. See [plugin development](../PLUGIN_DEVELOPMENT.md)
for component registration and runtime bundle examples.
