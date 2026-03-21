<?php

declare(strict_types=1);

use App\GraphQL\Mutations\CreateOrderMutation;
use App\GraphQL\Mutations\CreateProductMutation;
use App\GraphQL\Mutations\CreateStockAdjustmentMutation;
use App\GraphQL\Mutations\CreateSupplierMutation;
use App\GraphQL\Mutations\DeleteProductMutation;
use App\GraphQL\Mutations\UpdateOrderMutation;
use App\GraphQL\Mutations\UpdateProductMutation;
use App\GraphQL\Mutations\UpdateSupplierMutation;
use App\GraphQL\Queries\CategoriesQuery;
use App\GraphQL\Queries\LocationsQuery;
use App\GraphQL\Queries\OrderQuery;
use App\GraphQL\Queries\OrdersQuery;
use App\GraphQL\Queries\ProductQuery;
use App\GraphQL\Queries\ProductsQuery;
use App\GraphQL\Queries\PurchaseOrderQuery;
use App\GraphQL\Queries\PurchaseOrdersQuery;
use App\GraphQL\Queries\StockAdjustmentsQuery;
use App\GraphQL\Queries\SupplierQuery;
use App\GraphQL\Queries\SuppliersQuery;
use App\GraphQL\Types\LocationType;
use App\GraphQL\Types\OrderItemInputType;
use App\GraphQL\Types\OrderItemType;
use App\GraphQL\Types\OrderType;
use App\GraphQL\Types\ProductCategoryType;
use App\GraphQL\Types\ProductType;
use App\GraphQL\Types\ProductVariantType;
use App\GraphQL\Types\PurchaseOrderItemType;
use App\GraphQL\Types\PurchaseOrderType;
use App\GraphQL\Types\StockAdjustmentType;
use App\GraphQL\Types\SupplierType;

return [

    // The prefix for routes; set to 'api/graphql' so it uses the api middleware
    // The package registers its own routes, but we also add an explicit route in routes/api.php
    'prefix' => 'graphql',

    // The routes to make GraphQL request. Either a string that will apply
    // to both query and mutation or an array containing the key 'query' and/or
    // 'mutation' with the according Route
    //
    // Example:
    //
    // Same route for both query and mutation
    //
    // 'routes' => 'path/to/query/{graphql_schema?}',
    //
    // or define each route
    //
    // 'routes' => [
    //     'query' => 'query/{graphql_schema?}',
    //     'mutation' => 'mutation/{graphql_schema?}',
    // ]
    //
    'routes' => '{graphql_schema?}',

    // The controller to use in GraphQL request. Either a string that will apply
    // to both query and mutation or an array containing the key 'query' and/or
    // 'mutation' with the according Controller and method
    //
    // Example:
    //
    // 'controllers' => \Rebing\GraphQL\GraphQLController::class . '@query',
    //
    // or
    //
    // 'controllers' => [
    //     'query' => \Rebing\GraphQL\GraphQLController::class . '@query',
    //     'mutation' => \Rebing\GraphQL\GraphQLController::class . '@query',
    // ]
    //
    'controllers' => \Rebing\GraphQL\GraphQLController::class . '@query',

    // Any middleware for the graphql route group
    // This middleware will apply to all schemas
    'middleware' => [],

    // Additional route group attributes
    //
    // Example:
    //
    // 'route_group_attributes' => ['guard' => 'api']
    //
    'route_group_attributes' => [],

    // The name of the default schema
    // Used when the route is used without specifying a schema
    'default_schema' => 'default',

    'schemas' => [
        'default' => [
            'query' => [
                'products' => ProductsQuery::class,
                'product' => ProductQuery::class,
                'orders' => OrdersQuery::class,
                'order' => OrderQuery::class,
                'suppliers' => SuppliersQuery::class,
                'supplier' => SupplierQuery::class,
                'purchaseOrders' => PurchaseOrdersQuery::class,
                'purchaseOrder' => PurchaseOrderQuery::class,
                'stockAdjustments' => StockAdjustmentsQuery::class,
                'locations' => LocationsQuery::class,
                'categories' => CategoriesQuery::class,
            ],
            'mutation' => [
                'createProduct' => CreateProductMutation::class,
                'updateProduct' => UpdateProductMutation::class,
                'deleteProduct' => DeleteProductMutation::class,
                'createOrder' => CreateOrderMutation::class,
                'updateOrder' => UpdateOrderMutation::class,
                'createStockAdjustment' => CreateStockAdjustmentMutation::class,
                'createSupplier' => CreateSupplierMutation::class,
                'updateSupplier' => UpdateSupplierMutation::class,
            ],
            'middleware' => ['auth:sanctum'],
            'method' => ['GET', 'POST'],
        ],
    ],

    // The types available in the application. You can then access it from the
    // @param TypeRegistry $registry facade like this: GraphQL::type('user')
    //
    // Example:
    //
    // 'types' => [
    //     App\GraphQL\Types\UserType::class,
    // ]
    //
    'types' => [
        'Product' => ProductType::class,
        'ProductCategory' => ProductCategoryType::class,
        'ProductVariant' => ProductVariantType::class,
        'Order' => OrderType::class,
        'OrderItem' => OrderItemType::class,
        'Supplier' => SupplierType::class,
        'PurchaseOrder' => PurchaseOrderType::class,
        'PurchaseOrderItem' => PurchaseOrderItemType::class,
        'StockAdjustment' => StockAdjustmentType::class,
        'Location' => LocationType::class,
        'OrderItemInput' => OrderItemInputType::class,
    ],

    // The types will be loaded on demand. Default is to load all types on each request
    // Can increase performance on schemes with many types
    // Presupposes the config type key matches the type class name property
    'lazyload_types' => true,

    // This callable will be passed the Error object for each errors GraphQL catch.
    // The method should return an array representing the error.
    // Typically:
    // [
    //     'message' => '',
    //     'locations' => []
    // ]
    'error_formatter' => [\Rebing\GraphQL\GraphQL::class, 'formatError'],

    /*
     * Custom Error Handling
     *
     * Expected handler signature is: function (array $errors, callable $formatter): array
     *
     * The default handler will pass exceptions to laravel and return
     * @see \Rebing\GraphQL\GraphQL::handleErrors()
     * $errors is an array of \GraphQL\Error\Error
     * $formatter is the default error formatter from the library
     */
    'errors_handler' => [\Rebing\GraphQL\GraphQL::class, 'handleErrors'],

    /*
     * Options to limit the query complexity and depth. See the doc
     * @ https://webonyx.github.io/graphql-php/security
     * for details. Defaults to 0 (disabled).
     */
    'security' => [
        'query_max_complexity' => 0,
        'query_max_depth' => 0,
        'disable_introspection' => false,
    ],

    /*
     * You can define your own pagination type.
     * Reference \Rebing\GraphQL\Support\PaginationType::class
     */
    'pagination_type' => \Rebing\GraphQL\Support\PaginationType::class,

    /*
     * You can define your own simple pagination type.
     * Reference \Rebing\GraphQL\Support\SimplePaginationType::class
     */
    'simple_pagination_type' => \Rebing\GraphQL\Support\SimplePaginationType::class,

    /*
     * Config for GraphiQL (see (https://github.com/graphql/graphiql).
     */
    'graphiql' => [
        'prefix' => '/graphiql',
        'controller' => \Rebing\GraphQL\GraphQLController::class . '@graphiql',
        'middleware' => [],
        'view' => 'graphql::graphiql',
        'display' => env('ENABLE_GRAPHIQL', false),
    ],

    /*
     * Overrides the default field resolver
     * See http://webonyx.github.io/graphql-php/data-fetching/#default-field-resolver
     *
     * Example:
     *
     * 'defaultFieldResolver' => function ($root, $args, $context, $info) {
     * },
     */
    'defaultFieldResolver' => null,

    /*
     * Any headers that will be added to the response returned by the default controller
     */
    'headers' => [],

    /*
     * Any JSON encoding options when returning a response from the default controller
     * See http://php.net/manual/function.json-encode.php for the full list of options
     */
    'json_encoding_options' => 0,

    /*
     * Automatic Persisted Queries (APQ)
     * See https://www.apollographql.com/docs/apollo-server/performance/apq/
     *
     * Note 1: this requires the `AutomaticPersistedQueriesMiddleware` being enabled
     *
     * Note 2: even if APQ is disabled per configuration and also the middleware
     *         itself is not added, the hash will still be extracted from the request,
     *         as it is done in the `GraphQLUploadMiddleware` already.
     */
    'apq' => [
        // Enable/Disable APQ - See https://www.apollographql.com/docs/apollo-server/performance/apq/#disabling-apq
        'enable' => env('GRAPHQL_APQ_ENABLE', false),

        // The cache driver to use for APQ
        'cache_driver' => env('GRAPHQL_APQ_CACHE_DRIVER', config('cache.default')),

        // The cache prefix
        'cache_prefix' => config('cache.prefix') . ':graphql:apq:',

        // The cache ttl in seconds - See https://www.apollographql.com/docs/apollo-server/performance/apq/#adjusting-cache-time-to-live-ttl
        'cache_ttl' => 300,
    ],

    /*
     * Execution middlewares
     */
    'execution_middleware' => [
        \Rebing\GraphQL\Support\ExecutionMiddleware\ValidateOperationParamsMiddleware::class,
        // AutomaticPersistedQueriesMiddleware listed even if APQ is disabled, as the
        // temporary parsed request data is also used by the GraphQLUploadMiddleware
        \Rebing\GraphQL\Support\ExecutionMiddleware\AutomaticPersistedQueriesMiddleware::class,
        \Rebing\GraphQL\Support\ExecutionMiddleware\AddAuthUserContextMiddleware::class,
    ],
];
