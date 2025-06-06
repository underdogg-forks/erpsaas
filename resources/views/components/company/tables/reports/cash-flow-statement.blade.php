<table class="w-full min-w-[50rem] divide-y divide-gray-200 dark:divide-white/5">
    <colgroup>
        @if(array_key_exists('account_code', $report->getHeaders()))
            <col span="1" style="width: 20%;">
            <col span="1" style="width: 55%;">
            <col span="1" style="width: 25%;">
        @else
            <col span="1" style="width: 65%;">
            <col span="1" style="width: 35%;">
        @endif
    </colgroup>
    <x-company.tables.header :headers="$report->getHeaders()" :alignment-class="[$report, 'getAlignmentClass']"/>
    @foreach($report->getCategories() as $accountCategory)
        <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
        <x-company.tables.category-header :category-headers="$accountCategory->header"
                                          :alignment-class="[$report, 'getAlignmentClass']"/>
        @foreach($accountCategory->data as $categoryAccount)
            <tr>
                @foreach($categoryAccount as $accountIndex => $categoryAccountCell)
                    <x-company.tables.cell :alignment-class="$report->getAlignmentClass($accountIndex)">
                        @if(is_array($categoryAccountCell) && isset($categoryAccountCell['name']))
                            @if($categoryAccountCell['name'] === 'Retained Earnings' && isset($categoryAccountCell['start_date']) && isset($categoryAccountCell['end_date']))
                                <x-filament::link
                                    color="primary"
                                    target="_blank"
                                    icon="heroicon-o-arrow-top-right-on-square"
                                    :icon-position="\Filament\Support\Enums\IconPosition::After"
                                    :icon-size="\Filament\Support\Enums\IconSize::Small"
                                    href="{{ \App\Filament\Company\Pages\Reports\IncomeStatement::getUrl([
                                            'startDate' => $categoryAccountCell['start_date'],
                                            'endDate' => $categoryAccountCell['end_date']
                                        ]) }}"
                                >
                                    {{ $categoryAccountCell['name'] }}
                                </x-filament::link>
                            @elseif(isset($categoryAccountCell['id']) && isset($categoryAccountCell['start_date']) && isset($categoryAccountCell['end_date']))
                                <x-filament::link
                                    color="primary"
                                    target="_blank"
                                    icon="heroicon-o-arrow-top-right-on-square"
                                    :icon-position="\Filament\Support\Enums\IconPosition::After"
                                    :icon-size="\Filament\Support\Enums\IconSize::Small"
                                    href="{{ \App\Filament\Company\Pages\Reports\AccountTransactions::getUrl([
                                            'startDate' => $categoryAccountCell['start_date'],
                                            'endDate' => $categoryAccountCell['end_date'],
                                            'selectedAccount' => $categoryAccountCell['id']
                                        ]) }}"
                                >
                                    {{ $categoryAccountCell['name'] }}
                                </x-filament::link>
                            @else
                                {{ $categoryAccountCell['name'] }}
                            @endif
                        @else
                            {{ $categoryAccountCell }}
                        @endif
                    </x-company.tables.cell>
                @endforeach
            </tr>
        @endforeach
        @foreach($accountCategory->types as $accountType)
            <tr class="bg-gray-50 dark:bg-white/5">
                @foreach($accountType->header as $accountTypeHeaderIndex => $accountTypeHeaderCell)
                    <x-company.tables.cell :alignment-class="$report->getAlignmentClass($accountTypeHeaderIndex)"
                                           :indent="$accountTypeHeaderIndex === 'account_name'" bold="true">
                        {{ $accountTypeHeaderCell }}
                    </x-company.tables.cell>
                @endforeach
            </tr>
            @foreach($accountType->data as $typeAccount)
                <tr>
                    @foreach($typeAccount as $accountIndex => $typeAccountCell)
                        <x-company.tables.cell :alignment-class="$report->getAlignmentClass($accountIndex)"
                                               :indent="$accountIndex === 'account_name'">
                            @if(is_array($typeAccountCell) && isset($typeAccountCell['name']))
                                @if($typeAccountCell['name'] === 'Retained Earnings' && isset($typeAccountCell['start_date']) && isset($typeAccountCell['end_date']))
                                    <x-filament::link
                                        color="primary"
                                        target="_blank"
                                        icon="heroicon-o-arrow-top-right-on-square"
                                        :icon-position="\Filament\Support\Enums\IconPosition::After"
                                        :icon-size="\Filament\Support\Enums\IconSize::Small"
                                        href="{{ \App\Filament\Company\Pages\Reports\IncomeStatement::getUrl([
                                            'startDate' => $typeAccountCell['start_date'],
                                            'endDate' => $typeAccountCell['end_date']
                                        ]) }}"
                                    >
                                        {{ $typeAccountCell['name'] }}
                                    </x-filament::link>
                                @elseif(isset($typeAccountCell['id']) && isset($typeAccountCell['start_date']) && isset($typeAccountCell['end_date']))
                                    <x-filament::link
                                        color="primary"
                                        target="_blank"
                                        icon="heroicon-o-arrow-top-right-on-square"
                                        :icon-position="\Filament\Support\Enums\IconPosition::After"
                                        :icon-size="\Filament\Support\Enums\IconSize::Small"
                                        href="{{ \App\Filament\Company\Pages\Reports\AccountTransactions::getUrl([
                                            'startDate' => $typeAccountCell['start_date'],
                                            'endDate' => $typeAccountCell['end_date'],
                                            'selectedAccount' => $typeAccountCell['id']
                                        ]) }}"
                                    >
                                        {{ $typeAccountCell['name'] }}
                                    </x-filament::link>
                                @else
                                    {{ $typeAccountCell['name'] }}
                                @endif
                            @else
                                {{ $typeAccountCell }}
                            @endif
                        </x-company.tables.cell>
                    @endforeach
                </tr>
            @endforeach
            <tr>
                @foreach($accountType->summary as $accountTypeSummaryIndex => $accountTypeSummaryCell)
                    <x-company.tables.cell :alignment-class="$report->getAlignmentClass($accountTypeSummaryIndex)"
                                           :indent="$accountTypeSummaryIndex === 'account_name'" bold="true">
                        {{ $accountTypeSummaryCell }}
                    </x-company.tables.cell>
                @endforeach
            </tr>
        @endforeach
        <tr>
            @foreach($accountCategory->summary as $accountCategorySummaryIndex => $accountCategorySummaryCell)
                <x-company.tables.cell :alignment-class="$report->getAlignmentClass($accountCategorySummaryIndex)"
                                       bold="true" :underline-bold="$loop->last">
                    {{ $accountCategorySummaryCell }}
                </x-company.tables.cell>
            @endforeach
        </tr>
        <tr>
            <td colspan="{{ count($report->getHeaders()) }}">
                <div class="min-h-12"></div>
            </td>
        </tr>
        </tbody>
    @endforeach
    <x-company.tables.footer :totals="$report->getOverallTotals()" :alignment-class="[$report, 'getAlignmentClass']"/>
</table>

<table class="w-full min-w-[50rem] divide-y border-t divide-gray-200 dark:divide-white/5">
    <colgroup>
        @if(array_key_exists('account_code', $report->getHeaders()))
            <col span="1" style="width: 20%;">
            <col span="1" style="width: 55%;">
            <col span="1" style="width: 25%;">
        @else
            <col span="1" style="width: 65%;">
            <col span="1" style="width: 35%;">
        @endif
    </colgroup>
    <x-company.tables.header :headers="$report->getOverviewHeaders()"
                             :alignment-class="[$report, 'getAlignmentClass']"/>
    @foreach($report->getOverview() as $overviewCategory)
        <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
        <x-company.tables.category-header :category-headers="$overviewCategory->header"
                                          :alignment-class="[$report, 'getAlignmentClass']"/>
        @foreach($overviewCategory->data as $overviewAccount)
            <tr>
                @foreach($overviewAccount as $overviewIndex => $overviewCell)
                    <x-company.tables.cell :alignment-class="$report->getAlignmentClass($overviewIndex)">
                        @if(is_array($overviewCell) && isset($overviewCell['name']))
                            @if(isset($overviewCell['id']) && isset($overviewCell['start_date']) && isset($overviewCell['end_date']))
                                <x-filament::link
                                    color="primary"
                                    target="_blank"
                                    icon="heroicon-o-arrow-top-right-on-square"
                                    :icon-position="\Filament\Support\Enums\IconPosition::After"
                                    :icon-size="\Filament\Support\Enums\IconSize::Small"
                                    href="{{ \App\Filament\Company\Pages\Reports\AccountTransactions::getUrl([
                                            'startDate' => $overviewCell['start_date'],
                                            'endDate' => $overviewCell['end_date'],
                                            'selectedAccount' => $overviewCell['id']
                                        ]) }}"
                                >
                                    {{ $overviewCell['name'] }}
                                </x-filament::link>
                            @else
                                {{ $overviewCell['name'] }}
                            @endif
                        @else
                            {{ $overviewCell }}
                        @endif
                    </x-company.tables.cell>
                @endforeach
            </tr>
        @endforeach
        <tr>
            @foreach($overviewCategory->summary as $overviewSummaryIndex => $overviewSummaryCell)
                <x-company.tables.cell :alignment-class="$report->getAlignmentClass($overviewSummaryIndex)" bold="true">
                    {{ $overviewSummaryCell }}
                </x-company.tables.cell>
            @endforeach
        </tr>
        @if($overviewCategory->header['account_name'] === 'Starting Balance')
            @foreach($report->getOverviewAlignedWithColumns() as $summaryRow)
                <tr>
                    @foreach($summaryRow as $summaryIndex => $summaryCell)
                        <x-company.tables.cell :alignment-class="$report->getAlignmentClass($summaryIndex)"
                                               :bold="$loop->parent->last"
                                               :underline-bold="$loop->parent->last && $summaryIndex === 'net_movement'"
                                               :underline-thin="$loop->parent->remaining === 1 && $summaryIndex === 'net_movement'"
                        >
                            {{ $summaryCell }}
                        </x-company.tables.cell>
                    @endforeach
                </tr>
            @endforeach
        @endif
        </tbody>
    @endforeach
</table>
