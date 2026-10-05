<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Tests\TestCase;

/**
 * The core methods plugins may call (docs/PLUGIN_DEVELOPMENT.md, "Core PHP
 * API") are a public contract. Between releases a method may only gain
 * trailing optional parameters: removing it, renaming or retyping a
 * parameter, changing a default, adding a required parameter or changing the
 * return type breaks installed plugins, so it must fail here first.
 *
 * Changing an entry below is a breaking change: note it in the changelog.
 */
final class CorePhpApiContractTest extends TestCase
{
    /**
     * Class::method => the parameters and return type plugins rely on.
     *
     * @var array<string, array{params: array<int, string>, returns: string, static?: bool}>
     */
    private const CONTRACT = [
        'App\Models\Inventory\StockAdjustment::adjust' => ['static' => true, 'returns' => 'App\Models\Inventory\StockAdjustment', 'params' => [
            'App\Models\Inventory\Product $product', 'int $quantity', 'string $type', '?string $reason = NULL', '?string $notes = NULL',
            '?Illuminate\Database\Eloquent\Model $reference = NULL', 'bool $allowNegative = true', '?App\Models\User $actor = NULL',
            '?int $locationId = NULL', 'bool $syncBins = true',
        ]],
        'App\Models\Inventory\StockAdjustment::adjustVariant' => ['static' => true, 'returns' => 'App\Models\Inventory\StockAdjustment', 'params' => [
            'App\Models\Inventory\ProductVariant $variant', 'int $quantity', 'string $type', '?string $reason = NULL', '?string $notes = NULL',
            '?Illuminate\Database\Eloquent\Model $reference = NULL', 'bool $allowNegative = true', '?App\Models\User $actor = NULL',
        ]],

        'App\Services\OrderService::create' => ['returns' => 'App\Models\Order\Order', 'params' => [
            'array $data', 'App\Models\User $creator', "string \$source = 'manual'", 'bool $adjustStock = true', 'bool $announce = true',
        ]],
        'App\Services\OrderService::cancel' => ['returns' => 'App\Models\Order\Order', 'params' => ['App\Models\Order\Order $order', '?App\Models\User $actor = NULL']],
        'App\Services\OrderService::approve' => ['returns' => 'App\Models\Order\Order', 'params' => ['App\Models\Order\Order $order', 'App\Models\User $approver', '?string $notes = NULL']],
        'App\Services\OrderService::reject' => ['returns' => 'App\Models\Order\Order', 'params' => ['App\Models\Order\Order $order', 'App\Models\User $approver', 'string $notes']],
        'App\Services\OrderService::restockForDeletion' => ['returns' => 'void', 'params' => ['App\Models\Order\Order $order', '?App\Models\User $actor = NULL']],
        'App\Services\OrderService::replaceItems' => ['returns' => 'string', 'params' => ['App\Models\Order\Order $order', 'array $items', '?App\Models\User $actor = NULL']],
        'App\Services\OrderService::transitionStatus' => ['returns' => 'App\Models\Order\Order', 'params' => ['App\Models\Order\Order $order', 'App\Enums\OrderStatus $to']],
        'App\Services\OrderService::restockableQuantities' => ['returns' => 'array', 'params' => ['App\Models\Order\Order $order']],

        'App\Services\ReturnOrderService::create' => ['returns' => 'App\Models\Order\ReturnOrder', 'params' => ['int $organizationId', '?App\Models\User $actor', 'array $data']],
        'App\Services\ReturnOrderService::approve' => ['returns' => 'App\Models\Order\ReturnOrder', 'params' => ['App\Models\Order\ReturnOrder $returnOrder', 'App\Models\User $actor']],
        'App\Services\ReturnOrderService::updateLines' => ['returns' => 'App\Models\Order\ReturnOrder', 'params' => ['App\Models\Order\ReturnOrder $returnOrder', 'App\Models\User $actor', 'array $lines']],
        'App\Services\ReturnOrderService::receive' => ['returns' => 'App\Models\Order\ReturnOrder', 'params' => ['App\Models\Order\ReturnOrder $returnOrder', '?App\Models\User $actor = NULL']],
        'App\Services\ReturnOrderService::complete' => ['returns' => 'App\Models\Order\ReturnOrder', 'params' => ['App\Models\Order\ReturnOrder $returnOrder', 'App\Models\User $actor']],
        'App\Services\ReturnOrderService::reject' => ['returns' => 'App\Models\Order\ReturnOrder', 'params' => ['App\Models\Order\ReturnOrder $returnOrder', 'App\Models\User $actor', '?string $reason = NULL']],
        'App\Services\ReturnOrderService::returnedQuantities' => ['returns' => 'Illuminate\Support\Collection', 'params' => ['App\Models\Order\Order $order']],

        'App\Services\Shipping\ShipmentService::create' => ['returns' => 'App\Models\Shipping\Shipment', 'params' => ['App\Models\Order\Order $order', 'array $data', '?App\Models\User $user = NULL']],
        'App\Services\Shipping\ShipmentService::markShipped' => ['returns' => 'App\Models\Shipping\Shipment', 'params' => [
            'App\Models\Shipping\Shipment $shipment', '?App\Models\User $user = NULL', '?Illuminate\Support\Carbon $at = NULL',
        ]],
        'App\Services\Shipping\ShipmentService::applyTrackingStatus' => ['returns' => 'App\Models\Shipping\Shipment', 'params' => [
            'App\Models\Shipping\Shipment $shipment', 'App\Enums\ShipmentStatus $status', '?string $detail = NULL', '?Illuminate\Support\Carbon $at = NULL',
        ]],
        'App\Services\Shipping\ShipmentService::cancel' => ['returns' => 'App\Models\Shipping\Shipment', 'params' => ['App\Models\Shipping\Shipment $shipment']],
        'App\Services\Shipping\ShipmentService::remainingQuantities' => ['returns' => 'array', 'params' => ['App\Models\Order\Order $order']],

        'App\Services\StockAuditService::create' => ['returns' => 'App\Models\Inventory\StockAudit', 'params' => ['int $organizationId', 'App\Models\User $actor', 'array $data']],
        'App\Services\StockAuditService::start' => ['returns' => 'App\Models\Inventory\StockAudit', 'params' => ['App\Models\Inventory\StockAudit $stockAudit', 'App\Models\User $actor']],
        'App\Services\StockAuditService::recordCount' => ['returns' => 'App\Models\Inventory\StockAuditItem', 'params' => [
            'App\Models\Inventory\StockAudit $stockAudit', 'App\Models\Inventory\StockAuditItem $item', 'App\Models\User $actor', 'int $countedQuantity', '?string $notes = NULL',
        ]],
        'App\Services\StockAuditService::complete' => ['returns' => 'int', 'params' => ['App\Models\Inventory\StockAudit $stockAudit', 'App\Models\User $actor', 'bool $allowUncounted = false']],

        'App\Services\ProductLocationStockService::quantityAt' => ['returns' => 'int', 'params' => ['App\Models\Inventory\Product $product', 'int $locationId']],
        'App\Services\ProductLocationStockService::onHandAt' => ['returns' => 'int', 'params' => ['App\Models\Inventory\Product $product', 'int $locationId']],
        'App\Services\ProductLocationStockService::breakdown' => ['returns' => 'Illuminate\Support\Collection', 'params' => ['App\Models\Inventory\Product $product', '?App\Models\User $viewer = NULL']],
        'App\Services\ProductLocationStockService::totalAssigned' => ['returns' => 'int', 'params' => ['App\Models\Inventory\Product $product']],
        'App\Services\ProductLocationStockService::move' => ['returns' => 'void', 'params' => ['App\Models\Inventory\Product $product', 'int $fromLocationId', 'int $toLocationId', 'int $quantity']],
        'App\Services\ProductLocationStockService::consume' => ['returns' => 'void', 'params' => ['App\Models\Inventory\Product $product', 'int $quantity', '?int $preferWarehouseId = NULL']],
        'App\Services\ProductLocationStockService::receive' => ['returns' => 'void', 'params' => ['App\Models\Inventory\Product $product', 'int $quantity', '?int $locationId = NULL']],
        'App\Services\ProductLocationStockService::applyDelta' => ['returns' => 'int', 'params' => ['App\Models\Inventory\Product $product', 'int $locationId', 'int $delta', 'bool $allowNegativeBin = false']],

        'App\Services\TrackedStockAllocationService::allocateForOrderItem' => ['returns' => 'int', 'params' => ['App\Models\Inventory\Product $product', 'int $quantity', 'App\Models\Order\OrderItem $orderItem']],
        'App\Services\TrackedStockAllocationService::releaseForOrderItem' => ['returns' => 'int', 'params' => ['App\Models\Order\OrderItem $orderItem', '?int $limit = NULL']],

        'App\Services\ScanLookupService::resolve' => ['returns' => '?array', 'params' => ['int $organizationId', 'string $code']],
        'App\Services\ScanLookupService::locationSummary' => ['returns' => 'array', 'params' => ['App\Models\Inventory\ProductLocation $location']],

        'App\Services\ReorderService::primarySupplier' => ['returns' => '?App\Models\Inventory\Supplier', 'params' => ['App\Models\Inventory\Product $product']],
        'App\Services\ReorderService::suggestedQuantity' => ['returns' => 'int', 'params' => ['App\Models\Inventory\Product $product', '?App\Models\Inventory\Supplier $primary = NULL']],
        'App\Services\ReorderService::createDraftPurchaseOrder' => ['returns' => 'App\Models\Purchasing\PurchaseOrder', 'params' => [
            'int $organizationId', 'int $supplierId', 'array $products', '?int $userId', 'string $notes', 'string $activityAction', 'string $activityDescription',
        ]],

        'App\Services\Organizations\OrganizationMembershipService::isMember' => ['returns' => 'bool', 'params' => ['App\Models\User $user', 'int $organizationId']],
        'App\Services\Organizations\OrganizationMembershipService::roleIn' => ['returns' => '?string', 'params' => ['App\Models\User $user', 'int $organizationId']],
        'App\Services\Organizations\OrganizationMembershipService::organizationsFor' => ['returns' => 'Illuminate\Database\Eloquent\Collection', 'params' => ['App\Models\User $user']],
        'App\Services\Organizations\OrganizationMembershipService::members' => ['returns' => 'Illuminate\Database\Eloquent\Builder', 'params' => ['App\Models\Auth\Organization|int $organization']],
        'App\Services\Organizations\OrganizationMembershipService::add' => ['returns' => 'App\Models\Auth\OrganizationMembership', 'params' => [
            'App\Models\Auth\Organization $organization', 'App\Models\User $user', "string \$role = 'member'", '?App\Models\User $actor = NULL',
        ]],
        'App\Services\Organizations\OrganizationMembershipService::changeRole' => ['returns' => 'App\Models\Auth\OrganizationMembership', 'params' => [
            'App\Models\Auth\Organization $organization', 'App\Models\User $user', 'string $role', '?App\Models\User $actor = NULL',
        ]],
        'App\Services\Organizations\OrganizationMembershipService::remove' => ['returns' => 'void', 'params' => [
            'App\Models\Auth\Organization $organization', 'App\Models\User $user', '?App\Models\User $actor = NULL',
        ]],
        'App\Services\Organizations\OrganizationMembershipService::createOrganization' => ['returns' => 'App\Models\Auth\Organization', 'params' => [
            'array $attributes', 'App\Models\User $admin', '?App\Models\User $actor = NULL',
        ]],
        'App\Services\Organizations\ActiveOrganization::userIn' => ['returns' => '?App\Models\User', 'params' => ['App\Models\User|int $user', 'int $organizationId']],
        'App\Services\Organizations\ActiveOrganization::runAs' => ['returns' => 'mixed', 'params' => [
            'App\Models\User $user', 'int $organizationId', 'App\Enums\Permission|array|string $permissions', 'callable $callback',
        ]],
        'App\Services\Organizations\ActiveOrganization::authorize' => ['returns' => 'App\Models\User', 'params' => [
            'App\Models\User $user', 'int $organizationId', "App\\Enums\\Permission|array|string \$permissions = array (\n)",
        ]],
    ];

    private static function type(?ReflectionType $type, ReflectionMethod $method): string
    {
        if ($type === null) {
            return '';
        }

        // `self` / `static` resolve to the declaring class, so a refactor
        // between them and the class name does not count as a change.
        if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['self', 'static'], true)) {
            return ($type->allowsNull() ? '?' : '').$method->getDeclaringClass()->getName();
        }

        return ltrim((string) $type, '\\');
    }

    /**
     * @return array<int, string>
     */
    private static function params(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $rendered = trim(self::type($parameter->getType(), $method).' $'.$parameter->getName());
            if ($parameter->isDefaultValueAvailable()) {
                $rendered .= ' = '.var_export($parameter->getDefaultValue(), true);
            }
            $params[] = $rendered;
        }

        return $params;
    }

    public function test_every_api_method_keeps_its_contract(): void
    {
        foreach (self::CONTRACT as $name => $contract) {
            [$class, $methodName] = explode('::', $name);
            $this->assertTrue(method_exists($class, $methodName), "{$name} was removed.");

            $method = new ReflectionMethod($class, $methodName);
            $this->assertTrue($method->isPublic(), "{$name} is no longer public.");
            $this->assertSame($contract['static'] ?? false, $method->isStatic(), "{$name} changed between static and instance.");
            $this->assertSame($contract['returns'], self::type($method->getReturnType(), $method), "{$name} changed its return type.");

            $actual = self::params($method);
            $this->assertSame(
                $contract['params'],
                array_slice($actual, 0, count($contract['params'])),
                "{$name} changed an existing parameter (name, type, default or position).",
            );

            foreach (array_slice($method->getParameters(), count($contract['params'])) as $added) {
                $this->assertTrue($added->isOptional(), "{$name} gained a required parameter \${$added->getName()}; new parameters must be optional and trailing.");
            }
        }
    }

    public function test_every_api_method_is_tagged_api(): void
    {
        $untagged = [];
        foreach (array_keys(self::CONTRACT) as $name) {
            [$class, $methodName] = explode('::', $name);
            $doc = (string) (new ReflectionMethod($class, $methodName))->getDocComment();
            if (! preg_match('/^\s*\*\s*@api\b/m', $doc)) {
                $untagged[] = $name;
            }
        }

        $this->assertSame([], $untagged, 'These API methods need an @api tag in their docblock.');
    }

    public function test_the_guide_lists_exactly_the_api_methods(): void
    {
        $guide = File::get(base_path('docs/PLUGIN_DEVELOPMENT.md'));
        $start = strpos($guide, "## Core PHP API\n");
        $this->assertNotFalse($start, 'docs/PLUGIN_DEVELOPMENT.md has no "Core PHP API" section.');
        $end = strpos($guide, "\n## ", $start + 1);
        $section = substr($guide, $start, $end === false ? null : $end - $start);

        $listed = [];
        preg_match_all('/^\| `([^`]+)` \| (.+) \|$/m', $section, $rows, PREG_SET_ORDER);
        foreach ($rows as [, $class, $methods]) {
            preg_match_all('/`(\w+)\(\)`/', $methods, $names);
            foreach ($names[1] as $method) {
                $listed[] = "{$class}::{$method}";
            }
        }

        $expected = array_keys(self::CONTRACT);
        sort($expected);
        sort($listed);

        $this->assertSame($expected, $listed, 'The guide\'s Core PHP API table and this contract disagree.');
    }
}
