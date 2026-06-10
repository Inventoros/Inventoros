<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Eye } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    audit: Object,
    locations: Array,
    auditTypes: Object,
});

const form = useForm({
    name: props.audit.name || '',
    description: props.audit.description || '',
    audit_type: props.audit.audit_type || 'cycle',
    warehouse_location_id: props.audit.warehouse_location_id || '',
    notes: props.audit.notes || '',
});

const submit = () => {
    form.put(route('stock-audits.update', props.audit.id));
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head title="Edit Stock Audit" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Stock Audits</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Edit ({{ audit.audit_number }})</span>
            </div>
        </template>

        <PageHeader title="Edit Stock Audit" :description="`${audit.audit_number} - ${audit.name}`">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.show', audit.id)">
                    <ArrowLeft :size="14" />
                    Back to Audit
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.show', audit.id)">
                    <Eye :size="14" />
                    View
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 max-w-3xl">
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Audit Details</h3></div>
                <div class="space-y-4 p-5">
                    <!-- Name -->
                    <div>
                        <label for="name" :class="fieldLabel">Audit Name <span class="text-status-danger">*</span></label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="255"
                            :class="fieldInput"
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" :class="fieldLabel">Description</label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            maxlength="1000"
                            :class="fieldArea"
                            placeholder="Describe the purpose of this audit..."
                        ></textarea>
                        <p v-if="form.errors.description" :class="fieldError">{{ form.errors.description }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- Audit Type -->
                        <div>
                            <label for="audit_type" :class="fieldLabel">Audit Type <span class="text-status-danger">*</span></label>
                            <select
                                id="audit_type"
                                v-model="form.audit_type"
                                required
                                :class="fieldInput"
                            >
                                <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <p v-if="form.errors.audit_type" :class="fieldError">{{ form.errors.audit_type }}</p>
                        </div>

                        <!-- Location -->
                        <div>
                            <label for="warehouse_location_id" :class="fieldLabel">Warehouse Location</label>
                            <select
                                id="warehouse_location_id"
                                v-model="form.warehouse_location_id"
                                :class="fieldInput"
                            >
                                <option value="">All Locations</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                    <span v-if="location.code">({{ location.code }})</span>
                                </option>
                            </select>
                            <p v-if="form.errors.warehouse_location_id" :class="fieldError">{{ form.errors.warehouse_location_id }}</p>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" :class="fieldLabel">Notes</label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="2000"
                            :class="fieldArea"
                            placeholder="Additional notes or instructions..."
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 border-t border-border-subtle p-5">
                    <Button variant="secondary" as="Link" :href="route('stock-audits.show', audit.id)">Cancel</Button>
                    <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing || !form.name">
                        {{ form.processing ? 'Saving...' : 'Update Audit' }}
                    </Button>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>

