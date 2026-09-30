<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Info } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    locations: Array,
    products: Array,
    auditTypes: Object,
});

const form = useForm({
    name: '',
    description: '',
    audit_type: 'cycle',
    warehouse_location_id: '',
    notes: '',
    product_ids: [],
});

const productSearch = ref('');
const selectAllProducts = ref(false);

const filteredProducts = computed(() => {
    if (!productSearch.value) return props.products;
    const search = productSearch.value.toLowerCase();
    return props.products.filter(
        p => p.name.toLowerCase().includes(search) || p.sku.toLowerCase().includes(search)
    );
});

const toggleSelectAll = () => {
    if (selectAllProducts.value) {
        form.product_ids = filteredProducts.value.map(p => p.id);
    } else {
        form.product_ids = [];
    }
};

const toggleProduct = (productId) => {
    const index = form.product_ids.indexOf(productId);
    if (index > -1) {
        form.product_ids.splice(index, 1);
    } else {
        form.product_ids.push(productId);
    }
};

const submit = () => {
    form.post(route('stock-audits.store'), {
        preserveScroll: true,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
const fieldHint = 'mt-1 text-xs text-text-tertiary';
const fieldCheckbox = 'rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring';
</script>

<template>
    <Head :title="t('stockAudits.create.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.stockAudits') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.new') }}</span>
            </div>
        </template>

        <PageHeader :title="t('stockAudits.create.title')" :description="t('stockAudits.create.description')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.index')">
                    <ArrowLeft :size="14" />
                    {{ t('stockAudits.backToList') }}
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 mx-auto max-w-4xl space-y-4">
            <!-- Audit Details -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('stockAudits.auditDetails') }}</h3></div>
                <div class="space-y-4 p-5">
                    <!-- Name -->
                    <div>
                        <label :class="fieldLabel">
                            {{ t('stockAudits.fields.name') }} <span class="text-status-danger">*</span>
                        </label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="255"
                            :class="fieldInput"
                            :placeholder="t('stockAudits.fields.namePlaceholder')"
                        />
                        <p v-if="form.errors.name" :class="fieldError">
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <!-- Description -->
                    <div>
                        <label :class="fieldLabel">
                            {{ t('common.description') }}
                        </label>
                        <textarea
                            v-model="form.description"
                            rows="3"
                            maxlength="1000"
                            :class="fieldArea"
                            :placeholder="t('stockAudits.fields.descriptionPlaceholder')"
                        ></textarea>
                        <p v-if="form.errors.description" :class="fieldError">
                            {{ form.errors.description }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- Audit Type -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('stockAudits.fields.auditType') }} <span class="text-status-danger">*</span>
                            </label>
                            <select
                                v-model="form.audit_type"
                                required
                                :class="fieldInput"
                            >
                                <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ t(`stockAudits.types.${value}`) }}</option>
                            </select>
                            <p :class="fieldHint">
                                <span v-if="form.audit_type === 'full'">{{ t('stockAudits.fields.typeHints.full') }}</span>
                                <span v-else-if="form.audit_type === 'cycle'">{{ t('stockAudits.fields.typeHints.cycle') }}</span>
                                <span v-else-if="form.audit_type === 'spot'">{{ t('stockAudits.fields.typeHints.spot') }}</span>
                            </p>
                            <p v-if="form.errors.audit_type" :class="fieldError">
                                {{ form.errors.audit_type }}
                            </p>
                        </div>

                        <!-- Location -->
                        <div>
                            <label :class="fieldLabel">
                                {{ t('stockAudits.fields.location') }}
                            </label>
                            <select
                                v-model="form.warehouse_location_id"
                                :class="fieldInput"
                            >
                                <option value="">{{ t('products.allLocations') }}</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                    <span v-if="location.code">({{ location.code }})</span>
                                </option>
                            </select>
                            <p :class="fieldHint">
                                {{ t('stockAudits.fields.locationHint') }}
                            </p>
                            <p v-if="form.errors.warehouse_location_id" :class="fieldError">
                                {{ form.errors.warehouse_location_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label :class="fieldLabel">
                            {{ t('common.notes') }}
                        </label>
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            maxlength="2000"
                            :class="fieldArea"
                            :placeholder="t('stockAudits.create.notesPlaceholder')"
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Product Selection (for cycle/spot audits) -->
            <Card v-if="form.audit_type !== 'full'" :padded="false">
                <div class="px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('stockAudits.create.selectProducts') }}</h3>
                    <p class="mt-1 text-sm text-text-tertiary">
                        {{ form.warehouse_location_id ? t('stockAudits.create.selectProductsHintAtLocation') : t('stockAudits.create.selectProductsHint') }}
                    </p>
                </div>
                <div class="p-5">
                    <!-- Product Search -->
                    <div class="mb-4">
                        <input
                            v-model="productSearch"
                            type="text"
                            :placeholder="t('stockAudits.create.searchProducts')"
                            :class="fieldInput"
                        />
                    </div>

                    <!-- Select All -->
                    <div class="mb-3 flex items-center gap-2">
                        <input
                            type="checkbox"
                            v-model="selectAllProducts"
                            @change="toggleSelectAll"
                            :class="fieldCheckbox"
                        />
                        <span class="text-sm text-text-secondary">
                            {{ t('stockAudits.create.selectAll', { count: filteredProducts.length }, filteredProducts.length) }}
                        </span>
                        <span v-if="form.product_ids.length > 0" class="text-xs text-brand">
                            {{ t('common.selected', { count: form.product_ids.length }) }}
                        </span>
                    </div>

                    <!-- Product List -->
                    <div class="max-h-64 overflow-y-auto rounded-lg border border-border-subtle divide-y divide-border-subtle">
                        <label
                            v-for="product in filteredProducts"
                            :key="product.id"
                            class="flex items-center gap-3 px-4 py-3 hover:bg-surface-sunken cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :checked="form.product_ids.includes(product.id)"
                                @change="toggleProduct(product.id)"
                                :class="fieldCheckbox"
                            />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-text-primary truncate">{{ product.name }}</p>
                                <p class="text-xs text-text-tertiary">SKU: {{ product.sku }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-text-primary">{{ product.stock }}</p>
                                <p class="text-xs text-text-tertiary">{{ t('stockAudits.create.inStock') }}</p>
                            </div>
                        </label>
                        <div v-if="filteredProducts.length === 0" class="px-4 py-8 text-center text-sm text-text-tertiary">
                            {{ t('products.noProductsFound') }}
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Info Box for Full Audit -->
            <Card v-if="form.audit_type === 'full'" :padded="false">
                <div class="flex gap-3 p-5">
                    <Info :size="20" class="shrink-0 text-brand" />
                    <div>
                        <p class="text-sm font-medium text-text-primary">{{ t('stockAudits.types.full') }}</p>
                        <p class="mt-1 text-xs text-text-secondary">
                            {{ form.warehouse_location_id ? t('stockAudits.create.fullAuditInfoAtLocation') : t('stockAudits.create.fullAuditInfo') }}
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex justify-end gap-3">
                <Button variant="secondary" as="Link" :href="route('stock-audits.index')">{{ t('common.cancel') }}</Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing || !form.name">
                    {{ form.processing ? t('common.creating') : t('stockAudits.create.submit') }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
