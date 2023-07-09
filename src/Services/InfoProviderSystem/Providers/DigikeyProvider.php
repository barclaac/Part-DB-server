<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Entity\Parts\ManufacturingStatus;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DigikeyProvider implements InfoProviderInterface
{

    public function __construct(private readonly HttpClientInterface $digikeyClient)
    {

    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'DigiKey',
            'description' => 'This provider uses the DigiKey API to search for parts.',
            'url' => 'https://www.digikey.com/',
        ];
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::FOOTPRINT,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::DATASHEET,
            ProviderCapabilities::PRICE,
        ];
    }

    public function getProviderKey(): string
    {
        return 'digikey';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function searchByKeyword(string $keyword): array
    {
        $request = [
            'Keywords' => $keyword,
            'RecordCount' => 50,
            'RecordStartPosition' => 0,
            'ExcludeMarketPlaceProducts' => 'true',
        ];

        $response = $this->digikeyClient->request('POST', '/Search/v3/Products/Keyword', [
            'json' => $request,
        ]);

        $response_array = $response->toArray();


        $result = [];
        $products = $response_array['Products'];
        foreach ($products as $product) {
            $result[] = new SearchResultDTO(
                provider_key: $this->getProviderKey(),
                provider_id: $product['DigiKeyPartNumber'],
                name: $product['ManufacturerPartNumber'],
                description: $product['ProductDescription'],
                manufacturer: $product['Manufacturer']['Value'] ?? null,
                mpn: $product['ManufacturerPartNumber'],
                preview_image_url: $product['PrimaryPhoto'] ?? null,
                manufacturing_status: $this->productStatusToManufacturingStatus($product['ProductStatus']),
                provider_url: 'https://digikey.com'.$product['ProductUrl'],
            );
        }

        return $result;
    }

    /**
     * Converts the product status from the Digikey API to the manufacturing status used in Part-DB
     * @param  string|null  $dk_status
     * @return ManufacturingStatus|null
     */
    private function productStatusToManufacturingStatus(?string $dk_status): ?ManufacturingStatus
    {
        return match ($dk_status) {
            null => null,
            'Active' => ManufacturingStatus::ACTIVE,
            'Obsolete' => ManufacturingStatus::DISCONTINUED,
            'Discontinued at Digi-Key' => ManufacturingStatus::EOL,
            'Last Time Buy' => ManufacturingStatus::EOL,
            'Not For New Designs' => ManufacturingStatus::NRFND,
            'Preliminary' => ManufacturingStatus::ANNOUNCED,
            default => ManufacturingStatus::NOT_SET,
        };
    }
}