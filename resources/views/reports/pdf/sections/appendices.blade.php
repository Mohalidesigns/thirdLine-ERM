<h2>A. Basis of preparation</h2>

<p>{{ $data['basis'] }}</p>

<p>
    Sections included in this pack, and their order, are configured for this organisation. A section showing "no
    records" means the underlying register was empty at the position date — it does not mean the section was omitted.
</p>

<h2>B. Register coverage at the position date</h2>

<table class="data">
    <thead>
        <tr>
            <th>Measure</th>
            <th style="width: 30mm;" class="num">Count</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($data['counts'] as $label => $count)
            <tr>
                <td>{{ $label }}</td>
                <td class="num">{{ number_format($count) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
