<?php

namespace Database\Seeders;

use App\Models\Ubigeo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the official INEI ubigeo catalog into the single `ubigeo` table
 * (ADR-009).
 *
 * Source dataset: RitchieRD/ubigeos-peru-data (CC0 public domain, 2026
 * update), using official INEI codes.
 *
 * Code composition (CHAR(6), DDPPDD):
 * - departamento: source gives 2 digits (e.g. "01") -> "010000"
 * - provincia: source gives 4 digits (e.g. "0101") -> "010100", parent
 *   is the departamento code.
 * - distrito: source gives the full 6 digits (e.g. "010101"), parent
 *   is the provincia code.
 *
 * Row counts: 25 departamentos, 196 provincias, 1892 distritos
 * (Callao is a departamento with provincia "CALLAO" of level
 * provincia, per the official structure).
 *
 * Idempotent: rows are upserted by primary code.
 */
class UbigeoSeeder extends Seeder
{
    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        $rows = [];

        // Departamentos: "01" -> "010000".
        $departamentos = json_decode(
            file_get_contents(database_path('data/1_ubigeo_departamentos.json')),
            true
        )['ubigeo_departamentos'];

        foreach ($departamentos as $departamento) {
            $code = str_pad((string) $departamento['ubigeo'], 6, '0');
            $rows[$code] = [
                'code' => $code,
                'name' => $departamento['departamento'],
                'level' => 'departamento',
                'parent_code' => null,
            ];
        }

        // Provincias: "0101" -> "010100", parent "010000".
        $provincias = json_decode(
            file_get_contents(database_path('data/2_ubigeo_provincias.json')),
            true
        )['ubigeo_provincias'];

        foreach ($provincias as $provincia) {
            $code = str_pad((string) $provincia['ubigeo'], 6, '0');
            $rows[$code] = [
                'code' => $code,
                'name' => $provincia['provincia'],
                'level' => 'provincia',
                'parent_code' => substr($code, 0, 2).'0000',
            ];
        }

        // Distritos: full 6 digits, parent "010100".
        $distritos = json_decode(
            file_get_contents(database_path('data/3_ubigeo_distritos.json')),
            true
        )['ubigeo_distritos'];

        foreach ($distritos as $distrito) {
            $code = (string) $distrito['ubigeo'];
            $rows[$code] = [
                'code' => $code,
                'name' => $distrito['distrito'],
                'level' => 'distrito',
                'parent_code' => substr($code, 0, 4).'00',
            ];
        }

        foreach (array_chunk(array_values($rows), self::CHUNK_SIZE) as $chunk) {
            Ubigeo::query()->upsert(
                $chunk,
                ['code'],
                ['name', 'level', 'parent_code']
            );
        }
    }
}
