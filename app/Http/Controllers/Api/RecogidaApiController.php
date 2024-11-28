<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Recogida;
use App\Models\Alumno;
use App\Models\Reporte;
use Barryvdh\DomPDF\Facade\PDF;

/**
 * Archivo: RecogidaApiController.php
 * Propósito: Controlador para gestionar datos relacionados con recogidas.
 * Autor: José Balam González Rojas
 * Fecha de Creación: 2024-11-19
 * Última Modificación: 2024-11-27
 */
class RecogidaApiController extends Controller
{
    /**
     * Obtener los alumnos disponibles para recogida por tutor en la fecha actual.
     * Este método busca a los alumnos asignados a un tutor que no hayan sido recogidos
     * en la fecha actual y estén presentes en la asistencia.
     */
    public function alumnosSinRecogida($idTutor)
    {
        $alumnos = Alumno::where('cesi_tutore_id', $idTutor)
            ->whereDoesntHave('recogidas', function ($query) {
                $query->whereDate('recogida_fecha', now()->toDateString());
            })
            ->whereHas('asistencias', function ($query) {
                $query->whereHas('pases', function ($paseQuery) {
                    $paseQuery->where('pase_status', 'presente');
                });
            })
            ->get();

        if ($alumnos->isEmpty()) {
            return response()->json(['message' => 'No hay alumnos disponibles para recogida'], 200);
        }

        return response()->json(['data' => $alumnos], 200);
    }

    /**
     * Crear una nueva recogida de alumnos para un tutor en una fecha específica.
     * Este método valida los datos de la solicitud, filtra los alumnos que no han sido
     * recogidos en la fecha indicada y genera una nueva recogida con los alumnos seleccionados.
     */
    public function generarRecogida(Request $request)
    {
        $validated = $request->validate([
            'recogida_fecha' => 'required|date',
            'recogida_observaciones' => 'nullable|string',
            'recogida_estatus' => 'required|in:pendiente,completa,cancelada',
            'cesi_tutore_id' => 'required|exists:tutores,id',
            'alumnos' => 'required|array',
            'alumnos.*' => 'exists:cesi_alumnos,id',
        ]);

        $alumnosSinRecogida = Alumno::where('cesi_tutore_id', $validated['cesi_tutore_id'])
            ->whereDoesntHave('recogidas', function ($query) use ($validated) {
                $query->whereDate('recogida_fecha', $validated['recogida_fecha']);
            })
            ->whereIn('id', $validated['alumnos'])
            ->get();

        if ($alumnosSinRecogida->isEmpty()) {
            return response()->json(['message' => 'No hay alumnos disponibles para recogida en esta fecha.'], 400);
        }

        $recogida = Recogida::create([
            'recogida_fecha' => $validated['recogida_fecha'],
            'recogida_observaciones' => $validated['recogida_observaciones'],
            'recogida_estatus' => $validated['recogida_estatus'],
            'cesi_tutore_id' => $validated['cesi_tutore_id'],
        ]);
        $recogida->alumnos()->attach($alumnosSinRecogida->pluck('id'));

        return response()->json(['message' => 'Recogida creada correctamente', 'data' => $recogida], 201);
    }

    /**
     * Obtener las recogidas asociadas a los alumnos de un tutor.
     * Este método devuelve todas las recogidas registradas para los alumnos de un tutor específico.
     */
    public function recogidasPorTutor($idTutor)
    {
        $alumnos = Alumno::where('cesi_tutore_id', $idTutor)->get();

        if ($alumnos->isEmpty()) {
            return response()->json(['message' => 'Este tutor no tiene alumnos'], 200);
        }

        $recogidas = Recogida::whereHas('alumnos', function ($query) use ($alumnos) {
            $query->whereIn('cesi_alumnos.id', $alumnos->pluck('id'));
        })->with('alumnos')->get();

        if ($recogidas->isEmpty()) {
            return response()->json(['message' => 'No hay recogidas registradas para este tutor'], 200);
        }

        return response()->json(['data' => $recogidas], 200);
    }

    /**
     * Obtener las recogidas por estatus.
     * Este método permite filtrar las recogidas por su estatus (pendiente, completa, o cancelada).
     */
    public function recogidasPorEstatus(Request $request)
    {

        $validated = $request->validate([
            'estatus' => 'required|in:pendiente,completa,cancelada',
        ]);

        $recogidas = Recogida::where('recogida_estatus', $validated['estatus'])
            ->with('alumnos')
            ->get();
        if ($recogidas->isEmpty()) {
            return response()->json(['message' => 'No hay recogidas con el estatus especificado'], 200);
        }
        return response()->json(['data' => $recogidas], 200);
    }

    /**
     * Generar un reporte en PDF de las recogidas asociadas a un tutor.
     * Este método genera un archivo PDF con la lista de recogidas de los alumnos de un tutor
     * y lo guarda en el almacenamiento.
     */
    public function generarReportePDF($idTutor)
    {
        $alumnos = Alumno::where('cesi_tutore_id', $idTutor)->pluck('id');

        $recogidas = Recogida::whereHas('alumnos', function ($query) use ($alumnos) {
            $query->whereIn('cesi_alumnos.id', $alumnos);  // Filtrar las recogidas por los alumnos del tutor
        })->with('alumnos')->get();

        if ($recogidas->isEmpty()) {
            return response()->json(['message' => 'No hay datos de recogidas para generar el reporte'], 200);
        }

        $pdf = PDF::loadView('reportes.recogidas', ['recogidas' => $recogidas]);
        $filePath = 'reportes/' . uniqid('reporte_') . '.pdf';
        $pdf->save(storage_path('app/public/' . $filePath));

        $reporte = Reporte::create([
            'reporte_pdf' => $filePath,
            'cesi_tutore_id' => $idTutor,
        ]);

        return response()->json([
            'message' => 'Reporte generado correctamente',
            'data' => ['url' => asset('storage/' . $filePath)],
        ], 201);
    }

    /**
     * Obtener los reportes generados para un tutor.
     * Este método devuelve todos los reportes generados por un tutor en particular.
     */
    public function reportesPorTutor($idTutor)
    {
        $reportes = Reporte::where('cesi_tutore_id', $idTutor)->get();
        if ($reportes->isEmpty()) {
            return response()->json(['message' => 'No hay reportes registrados para este tutor'], 200);
        }
        return response()->json(['data' => $reportes], 200);
    }
}
