<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 text-slate-900">
    <header class="space-y-4">
        <a href="/" class="text-xl font-semibold">{{ $hotel['name'] }}</a>
        <nav aria-label="Hotel website" class="flex flex-wrap gap-4">
            <a href="/about">About</a>
            <a href="/rooms">Rooms</a>
            <a href="/facilities">Facilities</a>
            <a href="/restaurant">Restaurant</a>
            <a href="/events">Events</a>
            <a href="/contact">Contact</a>
            <a href="/booking">Book a stay</a>
        </nav>
    </header>
    <main class="space-y-6">
        <h1 class="text-3xl font-semibold">{{ $title }}</h1>
        @if($summary)<p>{{ $summary }}</p>@endif
        @if($body)<div>{!! $body !!}</div>@endif
        @foreach($sections as $section)
            <section class="space-y-3">
                @if($section['title'])<h2 class="text-2xl font-semibold">{{ $section['title'] }}</h2>@endif
                @if($section['subtitle'])<p>{{ $section['subtitle'] }}</p>@endif
                @if($section['body'])<div>{!! $section['body'] !!}</div>@endif
                @foreach($section['items'] as $item)
                    @if(is_array($item))
                        <div>
                            @if($item['question'] ?? $item['title'] ?? null)<h3 class="text-lg font-semibold">{{ $item['question'] ?? $item['title'] }}</h3>@endif
                            @if($item['answer'] ?? $item['description'] ?? null)<p>{{ $item['answer'] ?? $item['description'] }}</p>@endif
                        </div>
                    @endif
                @endforeach
            </section>
        @endforeach
        @if($rooms)
            <section><h2 class="text-2xl font-semibold">Rooms and suites</h2>
                @foreach($rooms as $room)
                    <article class="my-4"><h3><a href="/rooms/{{ $room['slug'] }}">{{ $room['title'] }}</a></h3><p>{{ $room['summary'] }}</p></article>
                @endforeach
            </section>
        @endif
        @if($offers)
            <section><h2 class="text-2xl font-semibold">Current offers</h2>
                @foreach($offers as $offer)
                    <article class="my-4"><h3><a href="/offers/{{ $offer['slug'] }}">{{ $offer['title'] }}</a></h3><p>{{ $offer['summary'] }}</p></article>
                @endforeach
            </section>
        @endif
        @if($amenities)
            <section><h2 class="text-2xl font-semibold">Room amenities</h2><ul>@foreach($amenities as $amenity)<li>{{ $amenity }}</li>@endforeach</ul></section>
        @endif
    </main>
    <footer class="space-y-2 border-t pt-4">
        <h2 class="text-xl font-semibold">Official hotel details</h2>
        <p>{{ implode(', ', array_filter([$hotel['address'], $hotel['city'], $hotel['state'], $hotel['country']])) }}</p>
        @if($hotel['phone'])<p>Phone: {{ $hotel['phone'] }}</p>@endif
        @if($hotel['email'])<p>Email: {{ $hotel['email'] }}</p>@endif
        <p>Check-in: {{ $booking['check_in_time'] }}. Check-out: {{ $booking['check_out_time'] }}. Currency: {{ $hotel['currency'] }}.</p>
        <p><a href="/contact">Contact reservations for current room availability and rates</a>.</p>
    </footer>
</div>
