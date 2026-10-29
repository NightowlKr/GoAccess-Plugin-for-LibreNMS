<div class="panel panel-default">
    <div class="panel-heading">GoAccess — Access Statistics</div>
    <div class="panel-body">
        @if ($reportError)
            <div class="alert alert-warning" role="alert">{{ $reportError }}</div>
        @elseif ($report !== null)
            <iframe title="GoAccess HTML report" sandbox="allow-scripts allow-downloads" referrerpolicy="no-referrer"
                    srcdoc="{{ $report }}" style="width:100%;height:80vh;border:0;"></iframe>
        @endif
    </div>
</div>
