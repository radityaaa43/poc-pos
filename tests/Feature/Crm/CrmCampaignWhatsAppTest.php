<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\CustomerCampaign;
use App\Models\CustomerCampaignLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CrmCampaignWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_a_campaign_sends_through_a_connected_gateway(): void
    {
        Permission::firstOrCreate(['name' => 'crm-campaigns-create', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('crm-campaigns-create');
        Customer::create(['name' => 'Siti', 'no_telp' => '628111999999', 'address' => 'Jl. Uji']);
        Setting::setForOutlet('wa_enabled', '1', null, 'WhatsApp gateway');
        Setting::setForOutlet('wa_service_url', 'http://wa.test', null, 'URL service WhatsApp');
        Http::fake([
            'wa.test/status' => Http::response(['connected' => true]),
            'wa.test/send' => Http::response(['status' => true]),
        ]);

        $this->actingAs($user)->post(route('crm-campaigns.store'), [
            'name' => 'Promo',
            'type' => CustomerCampaign::TYPE_PROMO_BROADCAST,
            'channel' => CustomerCampaign::CHANNEL_WHATSAPP_LINK,
            'message_template' => 'Halo {{name}}',
            'save_as_draft' => false,
            'audience_filters' => ['customer_type' => 'all'],
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send'));
        $this->assertSame(1, CustomerCampaignLog::where('status', CustomerCampaignLog::STATUS_SENT)->count());
        $this->assertSame(CustomerCampaign::STATUS_PROCESSED, CustomerCampaign::firstOrFail()->status);
    }
}
