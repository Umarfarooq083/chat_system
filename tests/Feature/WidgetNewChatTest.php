<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class WidgetNewChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_can_start_new_chat_after_close(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $visitorId = 'visitor_12345';
        $companyId = '123e4567-e89b-12d3-a456-426614174000';

        $first = $this->postJson('/api/widget/chat', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
            'current_url' => 'https://example.test/a',
        ]);

        $first->assertOk();
        $firstChatId = $first->json('chat.id');
        $this->assertNotNull($firstChatId);
        $this->assertSame('open', $first->json('chat.status'));

        Chat::query()->whereKey($firstChatId)->update(['status' => 'close']);

        $same = $this->postJson('/api/widget/chat', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
            'current_url' => 'https://example.test/b',
        ]);
        $same->assertOk();
        $this->assertSame('close', $same->json('chat.status'));
        $this->assertSame($firstChatId, $same->json('chat.id'));

        $new = $this->postJson('/api/widget/chat/new', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
            'current_url' => 'https://example.test/c',
        ]);
        $new->assertOk();
        $this->assertSame('open', $new->json('chat.status'));
        $this->assertNotSame($firstChatId, $new->json('chat.id'));
        $this->assertSame($visitorId, $new->json('chat.visitor_id'));
    }

    public function test_widget_requires_menu_choice_before_chatting_with_agent(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake([
            '*' => Http::response(['meta' => ['data' => rawurlencode('<html><body>Ledger</body></html>')]], 200),
        ]);
        Storage::fake('public');

        $visitorId = 'visitor_12345';
        $companyId = '123e4567-e89b-12d3-a456-426614174000';

        $chatResponse = $this->postJson('/api/widget/chat', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
        ])->assertOk();

        $chatId = $chatResponse->json('chat.id');
        $this->assertTrue($chatResponse->json('chat.prechat_required'));

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'prechat_info_response',
            'customer_name' => 'Test Visitor',
            'phone' => '03001234567',
            'message' => json_encode(['type' => 'prechat_info_response', 'name' => 'Test Visitor', 'phone' => '03001234567']),
        ])->assertOk();

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message' => 'Hello agent',
        ])->assertStatus(409);

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'user_info_response',
            'phone' => '03001234567',
            'customer_name' => 'Test Visitor',
            'registration_no' => ['REG-1'],
            'message' => 'Ledger info',
        ])->assertOk();

        $chat = Chat::findOrFail($chatId);
        $this->assertNull($chat->agent_chat_requested_at);

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'chat_with_agent_request',
            'message' => 'Chat with agent requested.',
        ])->assertOk();

        $chat->refresh();
        $this->assertNotNull($chat->agent_chat_requested_at);
        $this->assertNotNull($chat->first_visitor_message_at);
        $this->assertSame('chat_with_agent_request', Message::query()->latest('id')->value('message_type'));

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message' => 'Now connected',
        ])->assertOk();
    }

    public function test_widget_ledger_submit_returns_downloadable_html_message(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Http::fake([
            '*' => Http::response([
                'meta' => [
                    'data' => rawurlencode('<html><head></head><body>Ledger File</body></html>'),
                    'status' => 'ok',
                ],
            ], 200),
        ]);

        $visitorId = 'visitor_ledger_123';
        $companyId = '123e4567-e89b-12d3-a456-426614174000';
        $chatId = $this->createPrechatReadyWidgetChat($visitorId, $companyId);

        $response = $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'user_info_response',
            'phone' => '03001234567',
            'customer_name' => 'Test Visitor',
            'registration_no' => ['REG-LEDGER-1'],
            'message' => 'Ledger info',
        ])->assertOk();

        $this->assertSame('external_data_html', $response->json('bot_messages.0.message_type'));
        $attachmentPath = Message::query()->where('message_type', 'external_data_html')->latest('id')->value('attachments');
        $this->assertNotNull($attachmentPath);
        Storage::disk('public')->assertExists($attachmentPath);
        $this->assertStringContainsString('Ledger File', Storage::disk('public')->get($attachmentPath));
    }

    public function test_widget_cnic_submit_returns_registration_numbers(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'files' => [
                        ['reg_no' => 'REG-1001'],
                        ['reg_no' => 'REG-1002'],
                    ],
                    'message' => 'Found',
                ],
            ], 200),
        ]);

        $visitorId = 'visitor_cnic_12345';
        $companyId = '123e4567-e89b-12d3-a456-426614174000';
        $chatId = $this->createPrechatReadyWidgetChat($visitorId, $companyId);

        $response = $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'cnic_response',
            'cnic' => '11111-1111111-1',
            'message' => 'CNIC: 11111-1111111-1',
        ])->assertOk();

        $this->assertSame('cnic_lookup_response', $response->json('bot_messages.0.message_type'));
        $payload = json_decode((string) $response->json('bot_messages.0.message'), true);
        $this->assertSame(['REG-1001', 'REG-1002'], $payload['registration_numbers']);
    }

    public function test_widget_can_fetch_ledger_by_clicking_cnic_registration_number(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Http::fakeSequence()
            ->push([
                'data' => [
                    'files' => [
                        ['reg_no' => 'REG-CLICK-1'],
                    ],
                ],
            ], 200)
            ->push([
                'meta' => [
                    'data' => rawurlencode('<html><body>Clicked Ledger</body></html>'),
                ],
            ], 200);

        $visitorId = 'visitor_cnic_click';
        $companyId = '123e4567-e89b-12d3-a456-426614174000';
        $chatId = $this->createPrechatReadyWidgetChat($visitorId, $companyId);

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'cnic_response',
            'cnic' => '11111-1111111-1',
            'message' => 'CNIC: 11111-1111111-1',
        ])->assertOk();

        $response = $this->postJson('/api/widget/ledger', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'registration_no' => 'REG-CLICK-1',
        ])->assertOk();

        $this->assertSame('external_data_html', $response->json('message.message_type'));
        $attachmentPath = Message::query()->where('message_type', 'external_data_html')->latest('id')->value('attachments');
        $this->assertNotNull($attachmentPath);
        Storage::disk('public')->assertExists($attachmentPath);
        $this->assertStringContainsString('Clicked Ledger', Storage::disk('public')->get($attachmentPath));
    }

    public function test_local_visitor_ledger_accepts_matching_payload_visitor_id(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Http::fake([
            '*' => Http::response([
                'meta' => [
                    'data' => rawurlencode('<html><body>Local Clicked Ledger</body></html>'),
                ],
            ], 200),
        ]);

        $visitorId = 'visitor_local_click';
        $chat = Chat::create([
            'visitor_id' => $visitorId,
            'status' => 'open',
            'last_message_at' => now(),
            'agent_last_read_at' => now(),
            'visitor_last_read_at' => now(),
        ]);

        Message::create([
            'chat_id' => $chat->id,
            'sender_type' => 'agent',
            'message_type' => 'cnic_lookup_response',
            'message' => json_encode([
                'type' => 'cnic_lookup_response',
                'registration_numbers' => ['REG-LOCAL-1'],
            ]),
        ]);

        $response = $this->postJson('/visitor-chat/ledger', [
            'visitor_id' => $visitorId,
            'chat_id' => $chat->id,
            'registration_no' => 'REG-LOCAL-1',
        ])->assertOk();

        $this->assertSame('external_data_html', $response->json('message.message_type'));
    }

    private function createPrechatReadyWidgetChat(string $visitorId, string $companyId): int
    {
        $chatResponse = $this->postJson('/api/widget/chat', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
        ])->assertOk();

        $chatId = (int) $chatResponse->json('chat.id');

        $this->post('/api/widget/message', [
            'visitor_id' => $visitorId,
            'chat_id' => $chatId,
            'company_id' => $companyId,
            'message_type' => 'prechat_info_response',
            'customer_name' => 'Test Visitor',
            'phone' => '03001234567',
            'message' => json_encode(['type' => 'prechat_info_response', 'name' => 'Test Visitor', 'phone' => '03001234567']),
        ])->assertOk();

        return $chatId;
    }
}
