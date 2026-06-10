<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Search, Pencil, Trash2, Tag, X } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    categories: Object,
    filters: Object,
    pluginComponents: Object,
});

const search = ref(props.filters?.search || '');
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingCategory = ref(null);
const categoryForm = ref({ name: '', description: '' });

const searchCategories = () => {
    router.get(route('categories.index'), {
        search: search.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    search.value = '';
    searchCategories();
};

const openCreateModal = () => {
    categoryForm.value = { name: '', description: '' };
    showCreateModal.value = true;
};

const openEditModal = (category) => {
    editingCategory.value = category;
    categoryForm.value = {
        name: category.name,
        description: category.description || '',
    };
    showEditModal.value = true;
};

const createCategory = () => {
    router.post(route('categories.store'), categoryForm.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            categoryForm.value = { name: '', description: '' };
        },
    });
};

const updateCategory = () => {
    router.put(route('categories.update', editingCategory.value.id), categoryForm.value, {
        onSuccess: () => {
            showEditModal.value = false;
            editingCategory.value = null;
            categoryForm.value = { name: '', description: '' };
        },
    });
};

const deleteCategory = (category) => {
    if (confirm(`Are you sure you want to delete "${category.name}"? This action cannot be undone.`)) {
        router.delete(route('categories.destroy', category.id));
    }
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <Head :title="t('nav.categories')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('categories.title') }}</span>
            </div>
        </template>

        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <PageHeader :title="t('categories.title')" description="Organize your catalog into categories.">
            <template #actions>
                <Button variant="default" size="sm" @click="openCreateModal">
                    <Plus :size="14" />
                    {{ t('categories.addCategory') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Search -->
        <Card class="mt-6">
            <form @submit.prevent="searchCategories" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.search') }}</label>
                    <div class="relative">
                        <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                        <input
                            id="search"
                            v-model="search"
                            type="text"
                            :placeholder="t('categories.searchPlaceholder')"
                            class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('common.search') }}
                    </Button>
                    <Button type="button" variant="secondary" size="sm" @click="clearFilters">{{ t('common.clear') }}</Button>
                </div>
            </form>
        </Card>

        <!-- Categories Grid -->
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Card v-for="category in categories.data" :key="category.id" hoverable>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <h3 class="mb-1 truncate text-base font-semibold text-text-primary">
                            {{ category.name }}
                        </h3>
                        <p v-if="category.description" class="text-sm text-text-secondary">
                            {{ category.description }}
                        </p>
                    </div>
                    <Badge variant="brand" size="sm">{{ category.products_count }} products</Badge>
                </div>

                <div class="mt-4 flex items-center justify-end gap-1 border-t border-border-subtle pt-4">
                    <button
                        type="button"
                        @click="openEditModal(category)"
                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-success"
                        :aria-label="t('common.edit')"
                    >
                        <Pencil :size="16" />
                    </button>
                    <button
                        type="button"
                        @click="deleteCategory(category)"
                        :disabled="category.products_count > 0"
                        class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent disabled:hover:text-text-tertiary"
                        :aria-label="t('common.delete')"
                    >
                        <Trash2 :size="16" />
                    </button>
                </div>
            </Card>

            <!-- Empty State -->
            <div v-if="categories.data.length === 0" class="col-span-full">
                <Card>
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <Tag :size="22" class="text-text-tertiary" />
                        <p class="text-sm text-text-tertiary">{{ t('categories.noCategoriesFound') }}</p>
                        <Button variant="default" size="sm" @click="openCreateModal">
                            <Plus :size="14" />
                            {{ t('categories.createFirst') }}
                        </Button>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="categories.data.length > 0" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('common.showing') }} <span class="font-medium text-text-secondary">{{ categories.from }}</span>
                {{ t('common.to') }} <span class="font-medium text-text-secondary">{{ categories.to }}</span>
                {{ t('common.of') }} <span class="font-medium text-text-secondary">{{ categories.total }}</span> {{ t('common.results') }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in categories.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>

        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <!-- Create Category Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showCreateModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('categories.createCategory') }}
                        </h3>
                        <button
                            type="button"
                            @click="showCreateModal = false"
                            class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary"
                            :aria-label="t('common.cancel')"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <form @submit.prevent="createCategory" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-text-secondary">
                                {{ t('categories.categoryName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="categoryForm.name"
                                type="text"
                                :class="inputClass"
                                :placeholder="t('categories.namePlaceholder')"
                                required
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-text-secondary">
                                {{ t('common.description') }}
                            </label>
                            <textarea
                                v-model="categoryForm.description"
                                rows="3"
                                class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                                :placeholder="t('categories.descPlaceholder')"
                            ></textarea>
                        </div>

                        <div class="mt-6 flex justify-end gap-2">
                            <Button type="button" variant="secondary" size="sm" @click="showCreateModal = false">{{ t('common.cancel') }}</Button>
                            <Button type="submit" variant="default" size="sm">{{ t('categories.createCategory') }}</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Edit Category Modal -->
        <Teleport to="body">
            <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="showEditModal = false"></div>
                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('categories.editCategory') }}
                        </h3>
                        <button
                            type="button"
                            @click="showEditModal = false"
                            class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-text-primary"
                            :aria-label="t('common.cancel')"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <form @submit.prevent="updateCategory" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-text-secondary">
                                {{ t('categories.categoryName') }} <span class="text-status-danger">*</span>
                            </label>
                            <input
                                v-model="categoryForm.name"
                                type="text"
                                :class="inputClass"
                                required
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-text-secondary">
                                {{ t('common.description') }}
                            </label>
                            <textarea
                                v-model="categoryForm.description"
                                rows="3"
                                class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            ></textarea>
                        </div>

                        <div class="mt-6 flex justify-end gap-2">
                            <Button type="button" variant="secondary" size="sm" @click="showEditModal = false">{{ t('common.cancel') }}</Button>
                            <Button type="submit" variant="default" size="sm">{{ t('common.update') }} {{ t('nav.categories') }}</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

