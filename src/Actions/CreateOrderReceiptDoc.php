<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Docs\Contracts\DocServiceInterface;
use AIArmada\Docs\Enums\DocType;
use AIArmada\Docs\Models\Doc;
use AIArmada\Orders\Actions\Concerns\BuildsOrderDocs;
use AIArmada\Orders\Models\Order;

final class CreateOrderReceiptDoc
{
    use BuildsOrderDocs;

    public function __construct(
        public DocServiceInterface $docService,
    ) {}

    public function execute(Order $order, string $transactionId, string $gateway): Doc
    {
        return $this->runWithinOrderOwnerScope($order, function () use ($order, $transactionId, $gateway): Doc {
            $existingReceipt = $this->findExistingDoc($order, DocType::Receipt);

            if ($existingReceipt instanceof Doc) {
                return $existingReceipt;
            }

            return $this->docService->create($this->buildDocData(
                order: $order,
                docType: DocType::Receipt,
                transactionId: $transactionId,
                gateway: $gateway,
                metadata: [
                    'generated_by' => static::class,
                ],
            ));
        });
    }
}
