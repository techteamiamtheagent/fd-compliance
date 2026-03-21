<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ComplianceLookupController extends Controller
{

    public function lookup(Request $request)
    {
        // Ensure JSON body is merged (for POST requests)
        $request->merge($request->json()->all());

        $validated = $request->validate([
            'query' => 'required|string',
            'postcode' => 'nullable|string',
        ]);

        $searchText = trim($validated['query'] . ' ' . ($validated['postcode'] ?? ''));

        /*
         |----------------------------------------------------------------------
         | Google Places Search
         |----------------------------------------------------------------------
         */
        $placesResponse = Http::withHeaders([
            'X-Goog-Api-Key' => config('services.google.key'),
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.rating,places.userRatingCount,places.googleMapsUri'
        ])->post('https://places.googleapis.com/v1/places:searchText', [
            'textQuery' => $searchText,
        ]);

        $place = $placesResponse->json('places.0', []);

        // Google Place Details
        $website = null;
        $phone = null;

        if (!empty($place['id'])) {
            $detailsResponse = Http::withHeaders([
                'X-Goog-Api-Key' => config('services.google.key'),
                'X-Goog-FieldMask' => 'websiteUri,nationalPhoneNumber,internationalPhoneNumber'
            ])->get("https://places.googleapis.com/v1/places/{$place['id']}");

            if ($detailsResponse->successful()) {
                $details = $detailsResponse->json();
                $website = $details['websiteUri'] ?? null;
                $phone = $details['internationalPhoneNumber'] ?? $details['nationalPhoneNumber'] ?? null;
            }
        }

        /*
         |----------------------------------------------------------------------
         | Companies House – Search
         |----------------------------------------------------------------------
         */
        $companiesResponse = Http::withBasicAuth(config('services.companies_house.key'), '')
            ->get('https://api.company-information.service.gov.uk/search/companies', [
                'q' => $validated['query'],
                'items_per_page' => 5,
            ]);

        $items = $companiesResponse->json('items', []);

        // Pick best match (first that contains the search query)
        $company = collect($items)->first(fn($item) =>
            str_contains(strtoupper($item['title'] ?? ''), strtoupper($validated['query']))
        ) ?? $items[0] ?? null;

        $companyProfile = null;
        if (!empty($company['company_number'])) {
            $profileResponse = Http::withBasicAuth(config('services.companies_house.key'), '')
                ->get("https://api.company-information.service.gov.uk/company/{$company['company_number']}");

            if ($profileResponse->successful()) {
                $companyProfile = $profileResponse->json();
            }
        }

        $registeredOfficeFormatted = null;
        if (!empty($companyProfile['registered_office_address'])) {
            $registeredOfficeFormatted = implode(', ', array_filter($companyProfile['registered_office_address']));
        }

        /*
         |----------------------------------------------------------------------
         | Normalized Response
         |----------------------------------------------------------------------
         */
        return response()->json([
            // Firm Overview
            'firmName'       => $validated['query'],
            'tradingNames'   => null,
            'registeredName' => $company['title'] ?? null,
            'companyNumber'  => $company['company_number'] ?? null,
            'address'        => $company['address_snippet'] ?? null,
            'website'        => $website,
            'phone'          => $phone,
            'email'          => null,
            'dateChecked'    => now()->toDateString(),

            // Trade Associations
            'nafdMember' => 'Unknown',
            'saifMember' => 'Unknown',

            // Google Reviews
            'googleUrl'    => $place['googleMapsUri'] ?? null,
            'googleRating' => $place['rating'] ?? null,
            'googleCount'  => $place['userRatingCount'] ?? null,
            'googleDate'   => now()->toDateString(),

            // Companies House
            'chStatus'        => $companyProfile['company_status'] ?? null,
            'sic'             => isset($companyProfile['sic_codes']) ? implode(', ', $companyProfile['sic_codes']) : null,
            'registeredOffice'=> $registeredOfficeFormatted,
            'nextAccounts'    => $companyProfile['accounts']['next_accounts']['due_on'] ?? null,
            'nextCS'          => $companyProfile['confirmation_statement']['next_due'] ?? null,
            'chUrl'           => !empty($company['company_number'])
                                ? 'https://find-and-update.company-information.service.gov.uk/company/' . $company['company_number']
                                : null,

            // CMA / FCA placeholders
            'cmaOnline' => 'Unknown',
            'splUrl' => null,
            'fcaDoPlans' => 'Unknown',

            // Risk
            'riskReviews' => ($place && ($place['rating'] ?? 0) >= 4 && ($place['userRatingCount'] ?? 0) >= 5) ? 'GREEN' : 'AMBER',
            'riskCH'      => $companyProfile ? 'GREEN' : 'AMBER',

            // Media
            'shopfrontData' => null,
        ]);
    }

}
