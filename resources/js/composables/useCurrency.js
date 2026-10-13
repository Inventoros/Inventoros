import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useCurrency() {
    const page = usePage();
    return {
        organizationCurrency: computed(() => page.props.regional?.currency || 'USD'),
        supportedCurrencies: computed(() => page.props.regional?.currencies || []),
    };
}
