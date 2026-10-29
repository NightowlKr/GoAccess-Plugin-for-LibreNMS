<div class="panel panel-default">
    <div class="panel-heading">GoAccess Settings — v0.1.4</div>
    <div class="panel-body">
        <form method="post" action="{{ route('plugin.update', ['plugin' => 'GoAccess']) }}">
            @csrf
            <div class="form-group">
                <label for="goaccess-report-path">HTML report absolute path</label>
                <input class="form-control" id="goaccess-report-path" name="settings[report_path]"
                       type="text" value="{{ $reportPath }}" maxlength="4096" required pattern="/.*">
                <p class="help-block">Path on the LibreNMS server, outside the public web root. Static, self-contained HTML only; maximum 20 MiB.</p>
            </div>
            @if ($pathError)
                <div class="alert alert-warning" role="alert">{{ $pathError }}</div>
            @else
                <div class="alert alert-success">The configured path points to a readable HTML file.</div>
            @endif
            <p>LibreNMS saves the path using its standard plugin settings. Invalid paths remain saved but cannot be displayed; validation runs after saving and on every report view.</p>
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-default" href="{{ url('plugin/GoAccess') }}">View report</a>
        </form>
    </div>
</div>
