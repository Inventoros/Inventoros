# Sales-channel order numbers

The orders list and details show a channel's human-readable order number next
to the internal Inventoros order number. Plugins set a string in
`metadata.channel_order_number` when creating an order through `OrderService`.
Existing Shopify imports also work through their stored `metadata.shopify.name`;
no reimport or data migration is required.

Order searches on web, REST, GraphQL, MCP and global search match these labels,
external IDs and external references, while retaining tenant and warehouse
restrictions. REST, GraphQL and the MCP list expose `channel_order_number`.
The channel label is display/search data: it does not replace Inventoros numbers
or the integration's unique deduplication reference.
