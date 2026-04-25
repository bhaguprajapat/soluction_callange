@extends('layouts.app')

@section('title', 'Dashboard - AutoRescue AI')

@section('content')
    <div class="card" style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; gap:12px;">
        <div>
            <h2 style="margin:0;">Emergency Dashboard</h2>
            <p class="muted" style="margin:6px 0 0;">User: {{ $user->name }} | Role: {{ $user->role }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn" type="submit">Logout</button>
        </form>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3 style="margin-top:0;">Trigger Emergency (Double Click Required)</h3>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
            @foreach (['fire', 'attack', 'medical', 'kidnap', 'other'] as $type)
                <button class="btn btn-primary emergency-btn" data-type="{{ $type }}" style="height:72px;">
                    {{ strtoupper($type) }}
                </button>
            @endforeach
        </div>
        <p class="muted" style="margin-top:10px;">Double click a button to confirm and send emergency signal.</p>
        <div id="emergencyStatus" style="margin-top: 12px;"></div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h3 style="margin-top:0;">Recent Emergencies</h3>
            <table>
                <thead>
                <tr>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>AI Message</th>
                </tr>
                </thead>
                <tbody id="emergencyTableBody">
                @forelse ($emergencies as $emergency)
                    <tr>
                        <td>{{ strtoupper($emergency->type) }}</td>
                        <td>{{ strtoupper($emergency->severity) }}</td>
                        <td>{{ strtoupper($emergency->status) }}</td>
                        <td>{{ data_get($emergency->ai_response, 'message', 'N/A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No emergencies yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 style="margin-top:0;">Alert Logs</h3>
            <table>
                <thead>
                <tr>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Emergency</th>
                    <th>Time</th>
                </tr>
                </thead>
                <tbody id="alertTableBody">
                @forelse ($alerts as $alert)
                    <tr>
                        <td>{{ strtoupper($alert->alert_type) }}</td>
                        <td>{{ strtoupper($alert->status) }}</td>
                        <td>{{ strtoupper($alert->emergency?->type ?? 'N/A') }}</td>
                        <td>{{ $alert->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No alerts yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const statusBox = document.getElementById('emergencyStatus');
        const alertTableBody = document.getElementById('alertTableBody');

        const setStatus = (msg, danger = false) => {
            statusBox.innerHTML = `<span class="tag ${danger ? 'warn' : ''}">${msg}</span>`;
        };

        document.querySelectorAll('.emergency-btn').forEach(btn => {
            btn.addEventListener('dblclick', async (event) => {
                event.preventDefault();
                const type = btn.dataset.type;
                const useCurrent = confirm('Emergency location options:\nPress OK for Current Location.\nPress Cancel to Select Other Location.');

                let chosenLocation = null;

                if (useCurrent) {
                    setStatus(`Capturing location for ${type.toUpperCase()}...`);
                    chosenLocation = await getCurrentLocation();
                } else {
                    chosenLocation = await pickOtherLocation();
                }

                if (!chosenLocation) {
                    setStatus('Location selection is required to trigger emergency.', true);
                    return;
                }

                try {
                    setStatus(`Submitting ${type.toUpperCase()} emergency...`);
                    const response = await fetch("{{ url('/api/emergencies/trigger') }}", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            type,
                            location_mode: chosenLocation.mode,
                            latitude: chosenLocation.latitude,
                            longitude: chosenLocation.longitude,
                            location_label: chosenLocation.label
                        })
                    });

                    const payload = await response.json();
                    if (!response.ok) {
                        setStatus(payload.message || 'Emergency request failed.', true);
                        return;
                    }

                    const ai = payload.data.ai_response || {};
                    const cluster = payload.data.cluster_event ? ' | CRITICAL CLUSTER EVENT' : '';
                    setStatus(`Emergency sent | Severity: ${payload.data.severity.toUpperCase()}${cluster}`);

                    const table = document.getElementById('emergencyTableBody');
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${type.toUpperCase()}</td>
                        <td>${(payload.data.severity || '').toUpperCase()}</td>
                        <td>ACTIVE</td>
                        <td>${ai.message || 'N/A'}</td>
                    `;
                    table.prepend(row);

                    await refreshAlerts();
                } catch (error) {
                    setStatus('Emergency request failed unexpectedly.', true);
                }
            });
        });

        async function getCurrentLocation() {
            if (!navigator.geolocation) {
                setStatus('Location access is required for emergency services', true);
                return null;
            }

            return new Promise((resolve) => {
                navigator.geolocation.getCurrentPosition((position) => {
                    resolve({
                        mode: 'current',
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        label: 'Current GPS Location'
                    });
                }, () => {
                    setStatus('Location access is required for emergency services', true);
                    resolve(null);
                }, { enableHighAccuracy: true, timeout: 10000 });
            });
        }

        async function pickOtherLocation() {
            const address = prompt('Search and select location (Google Maps address/place):');
            if (!address) {
                return null;
            }

            const response = await fetch(`{{ url('/api/maps/geocode') }}?address=${encodeURIComponent(address)}`, {
                headers: { 'Accept': 'application/json' }
            });

            const payload = await response.json();
            if (!response.ok) {
                setStatus(payload.message || 'Unable to resolve selected location.', true);
                return null;
            }

            return {
                mode: 'custom',
                latitude: payload.latitude,
                longitude: payload.longitude,
                label: payload.formatted_address || address
            };
        }

        async function refreshAlerts() {
            const response = await fetch("{{ url('/api/dashboard/alerts') }}", {
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json();

            alertTableBody.innerHTML = '';
            (payload.data || []).forEach(alert => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${(alert.alert_type || 'N/A').toUpperCase()}</td>
                    <td>${(alert.status || 'N/A').toUpperCase()}</td>
                    <td>${(alert.emergency_type || 'N/A').toUpperCase()}</td>
                    <td>${alert.created_at || ''}</td>
                `;
                alertTableBody.appendChild(row);
            });
        }
    </script>
@endsection
