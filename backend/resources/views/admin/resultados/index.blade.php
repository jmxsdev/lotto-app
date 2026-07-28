@extends('admin.layouts.app')

@section('title', 'Resultados de Sorteos')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-trophy"></i> Resultados de Sorteos</h1>
    <form action="{{ route('admin.resultados.scrape') }}" method="POST" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-primary" id="btn-scrape">
            <i class="bi bi-arrow-clockwise"></i> Ejecutar Scraper Manual
        </button>
    </form>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Filtros</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('admin.resultados.index') }}" class="row g-3">
            <div class="col-md-4">
                <label for="fecha" class="form-label">Fecha</label>
                <input type="date" class="form-control" id="fecha" name="fecha" value="{{ request('fecha', now()->format('Y-m-d')) }}">
            </div>
            <div class="col-md-4">
                <label for="juego_id" class="form-label">Juego</label>
                <select class="form-select" id="juego_id" name="juego_id">
                    <option value="">Todos los juegos</option>
                    @foreach($juegos as $juego)
                        <option value="{{ $juego->id }}" {{ request('juego_id') == $juego->id ? 'selected' : '' }}>
                            {{ $juego->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-secondary">
                    <i class="bi bi-funnel"></i> Filtrar
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Resultados del {{ \Carbon\Carbon::parse(request('fecha', now()))->format('d/m/Y') }}</h5>
    </div>
    <div class="card-body">
        @if($resultados->count() > 0)
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Hora</th>
                            <th>Juego</th>
                            <th>Animal</th>
                            <th>Número</th>
                            <th>País</th>
                            <th>Imagen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($resultados as $resultado)
                            @php
                                $numeros = is_string($resultado->numeros_ganadores)
                                    ? json_decode($resultado->numeros_ganadores, true)
                                    : $resultado->numeros_ganadores;
                            @endphp
                            <tr>
                                <td>{{ $resultado->hora_sorteo }}</td>
                                <td>{{ $resultado->juego->name ?? 'N/A' }}</td>
                                <td>
                                    <span class="badge bg-{{ $numeros['color_animal'] ?? 'secondary' }}">
                                        {{ $numeros['nombre_animal'] ?? 'N/A' }}
                                    </span>
                                </td>
                                <td>
                                    <strong>{{ $numeros['numero'] ?? $numeros['triple_a'] ?? 'N/A' }}</strong>
                                </td>
                                <td>{{ $numeros['pais'] ?? 'N/A' }}</td>
                                <td>
                                    @if($numeros['imagen_animal'] ?? null)
                                        <img src="https://admin.lottoactivo.com/dist/animals_img/{{ $numeros['imagen_animal'] }}" 
                                             alt="{{ $numeros['nombre_animal'] ?? '' }}" 
                                             style="width: 40px; height: 40px; object-fit: contain;">
                                    @else
                                        <i class="bi bi-image text-muted"></i>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center">
                {{ $resultados->links() }}
            </div>
        @else
            <div class="alert alert-info text-center">
                <i class="bi bi-info-circle"></i> No hay resultados para la fecha seleccionada.
            </div>
        @endif
    </div>
</div>

@if($ultimoLog)
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">Último Scrape</h5>
        </div>
        <div class="card-body">
            <p><strong>Fecha:</strong> {{ $ultimoLog->created_at->format('d/m/Y H:i:s') }}</p>
            <p><strong>Estado:</strong> 
                @php
                    $details = json_decode($ultimoLog->details, true);
                    $level = $details['level'] ?? 'info';
                @endphp
                <span class="badge bg-{{ $level === 'error' ? 'danger' : ($level === 'warning' ? 'warning' : 'success') }}">
                    {{ strtoupper($level) }}
                </span>
            </p>
            <p><strong>Mensaje:</strong> {{ $details['message'] ?? 'N/A' }}</p>
            @if(isset($details['resultados_guardados']))
                <p><strong>Resultados guardados:</strong> {{ $details['resultados_guardados'] }}</p>
            @endif
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
    document.getElementById('btn-scrape').addEventListener('click', function() {
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Ejecutando...';
    });
</script>
@endpush
