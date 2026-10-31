<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\Inventory\VariantReplenishmentPolicy;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Organizations\ActiveOrganization;
use App\Services\VariantReplenishmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ReplenishVariantsCommand extends Command
{
    protected $signature = 'inventory:replenish-variants {--organization= : Limit to one organization}';

    protected $description = 'Create draft purchase orders for explicitly configured variant/warehouse policies';

    public function handle(VariantReplenishmentService $replenishment, ActiveOrganization $organizations): int
    {
        $lock = Cache::lock('inventory:replenish-variants', 900);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        $created = $skipped = $errors = 0;
        try {
            $query = VariantReplenishmentPolicy::withoutGlobalScope(OrganizationScope::class)->where('auto_reorder', true);
            if ($this->option('organization') !== null) {
                if (! ctype_digit((string) $this->option('organization')) || (int) $this->option('organization') < 1) {
                    return self::INVALID;
                }
                $query->where('organization_id', (int) $this->option('organization'));
            }
            $query->chunkById(100, function ($policies) use ($replenishment, $organizations, &$created, &$skipped, &$errors): void {
                // Batch by organization before estimating; no cross-tenant totals.
                foreach ($policies->groupBy('organization_id') as $group) {
                    $forecasts = $replenishment->forecasts($group);
                    foreach ($group as $policy) {
                        if ($forecasts[$policy->id]['suggested_quantity'] === 0) {
                            $skipped++;

                            continue;
                        }
                        try {
                            $actor = User::find($policy->managed_by);
                            if (! $actor) {
                                $skipped++;

                                continue;
                            }
                            $po = $organizations->runAs($actor, (int) $policy->organization_id,
                                ['view_products', 'edit_products', 'create_purchase_orders'],
                                fn (User $member) => $replenishment->createDraft($member, $policy, automatic: true));
                            $po ? $created++ : $skipped++;
                        } catch (BusinessRuleException|ValidationException|HttpException|AuthorizationException|ModelNotFoundException $error) {
                            $skipped++;
                            $this->warn('Skipped unavailable or unauthorized policy '.$policy->id);
                        } catch (Throwable $error) {
                            $errors++;
                            Log::error('Variant replenishment failed', ['policy_id' => $policy->id, 'exception_type' => $error::class]);
                        }
                    }
                }
            });
        } finally {
            $lock->release();
        }
        $this->info("Created {$created} draft purchase orders; skipped {$skipped} policies; errors {$errors}.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
