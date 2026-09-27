{!! $brandName !!}

{!! $reportName !!}

Your {!! $frequency !!} report from {!! $brandName !!} is attached as {!! $filename !!}.

Report: {!! $reportName !!}
Schedule: {!! ucfirst($frequency) !!}
Generated: {!! $generatedAt !!}

This report was scheduled by a member of your organization. Ask them to change or stop the schedule if you no longer need it.
@include('emails.text.partials.footer')
