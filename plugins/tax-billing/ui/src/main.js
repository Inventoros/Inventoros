import Settings from './Settings.vue';
import Invoice from './Invoice.vue';
import CustomerFields from './CustomerFields.vue';
import CustomerTaxDetails from './CustomerTaxDetails.vue';
import InvoiceLink from './InvoiceLink.vue';
import './style.css';
export default function setup(plugin) {
    plugin.registerPage('Settings', Settings);
    plugin.registerPage('Invoice', Invoice);
    plugin.registerComponent('CustomerFields', CustomerFields);
    plugin.registerComponent('CustomerTaxDetails', CustomerTaxDetails);
    plugin.registerComponent('InvoiceLink', InvoiceLink);
}
