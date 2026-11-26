<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportPreset;
use App\Http\Resources\Api\BankImportResource;
use App\Models\Account;
use App\Models\BankImport;
use App\Services\CsvImport\ApiCsvImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ImportController extends OwnedReadController
{
    public const int MAX_KILOBYTES = 10240;

    public function store(Request $request, ApiCsvImporter $importer): JsonResponse
    {
        $user = $this->owner($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.self::MAX_KILOBYTES],
            'account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')->where('user_id', $user->id)],
            'preset' => ['sometimes', 'nullable', Rule::enum(ImportPreset::class)->except(ImportPreset::Qif)],
        ]);

        $account = Account::query()->where('user_id', $user->id)->findOrFail((int) $validated['account_id']);

        $bankImport = $importer->import(
            $user,
            $account,
            $request->file('file'),
            ImportPreset::tryFrom((string) ($validated['preset'] ?? '')) ?? ImportPreset::BankCsv,
        );

        return new BankImportResource($bankImport->refresh())->response()->setStatusCode(JsonResponse::HTTP_ACCEPTED);
    }

    public function show(Request $request, int $id): BankImportResource
    {
        return new BankImportResource(BankImport::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }
}
