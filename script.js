jQuery(document).ready(function ($) {

    $.post(
        'https://www.googleapis.com/geolocation/v1/geolocate?key=AIzaSyBfxcYaq_RYxjF9GU_Du1g858jYDBU87Wk',
        {},
        function (response) {
            setCookie('geolocation', JSON.stringify(response.location), 2);
        }
    );


    if (getCookie('productsIds') !== undefined) {
        const ids = getCookie('productsIds');
        const checkboxes = $('.product-item').toArray();

        if (ids) {
            const idsArray = ids.split(',');
            checkboxes.forEach((item, index) => {
                if (idsArray.indexOf($(item).attr('data-id')) > -1) {
                    $(item).find('.where-buy-checkbox-style').addClass('active');
                }
            })
            setCookie('productsIds', [], 2);
        } else {
            checkboxes.forEach((item, index) => {
                $(item).find('.where-buy-checkbox-style').addClass('active');
            })
        }
    } else {
        const checkboxes = $('.product-item').toArray();
        checkboxes.forEach((item, index) => {
            $(item).find('.where-buy-checkbox-style').addClass('active');
        })
    }

    $('.where-buy-checkbox-style').click(function () {
        $(this).toggleClass('active')
    });

    $(document).on('click', '.toggle-product-menu', function () {
        if ($(this).hasClass('icon-down')) {
            $(this).removeClass('icon-down').addClass('icon-up');
            $(document).find('.for-mobile-display-products .products').css({'display': 'none'})
        } else {
            $(this).removeClass('icon-up').addClass('icon-down');
            $(document).find('.for-mobile-display-products .products').css({'display': 'block'})
        }
    });

    $('#by-online-desktop, #by-online-mobile').click(function () {
        $(document).find('.switch-on').removeClass('switch-on');
        $('#by-online-desktop').addClass('switch-on');
        $('#by-online-mobile').addClass('switch-on');
        $(document).find('.find-near-by-wrapper').addClass('hidden');
        $(document).find('.buy-online-wrapper').removeClass('hidden');
    });

    $('#find-nearby-desktop,#find-nearby-mobile').click(function () {
        $(document).find('.switch-on').removeClass('switch-on');
        $('#find-nearby-desktop').addClass('switch-on');
        $('#find-nearby-mobile').addClass('switch-on');
        $(document).find('.find-near-by-wrapper').removeClass('hidden');
        $(document).find('.buy-online-wrapper').addClass('hidden');
    });

    $('#search-retailer-by-zip, .reload-spinner').click(function () {

        let locationVal = '';
        if (window.innerWidth <= 985) {
            locationVal = $('.for-mobile-display .where-find-by-input').val();
        } else {
            locationVal = $('.for-desktop-display .where-find-by-input').val();

            if ($(this).hasClass('reload-spinner')) {
                locationVal = localStorage.getItem('location');
            }
        }

        let className = '.for-desktop-display-products .where-buy-checkbox-style.active';

        if (window.innerWidth <= 985) {
            className = '.for-mobile-display-products .where-buy-checkbox-style.active';
        }

        const productsIds = $(document).find(className).map((index, item) => {

            return $(item).closest('.product-item').attr('data-id');
        });

        $('.reload-spinner').addClass('active');

        if (!locationVal.length || !productsIds.length) {
            setInformation('Select a product and enter your city, state or ZIP.');
            return false;
        }

        localStorage.setItem('location', locationVal);
        localStorage.setItem('productsIds', productsIds.toArray());
        setCookie('productsIds', productsIds.toArray(), 2);
        setCookie('location', locationVal, 2);


        if (isNaN(locationVal)) {

            $.post(
                '/wp-admin/admin-ajax.php',
                {
                    'action': 'search_retailers',
                    location: locationVal,
                    'productsIds': productsIds.toArray(),
                },
                function (response) {

                    if (response.length <= 2) {
                        setInformation('There are no items near your location')
                    } else {
                        setCookie('retailers_location', response, 2);
                        localStorage.setItem('retailers_location', response);
                        document.location.reload(true);
                    }
                    $('.reload-spinner').removeClass('active');
                }
            );

        } else {
            $.ajax({
                url: "https://maps.googleapis.com/maps/api/geocode/json?key=AIzaSyBfxcYaq_RYxjF9GU_Du1g858jYDBU87Wk&address=" + locationVal + "&sensor=false",
                method: "POST",
                success: function (data) {
                    if (data.results.length) {
                        let latitude = data.results[0].geometry.location.lat;
                        let longitude = data.results[0].geometry.location.lng;

                        $.post(
                            '/wp-admin/admin-ajax.php',
                            {
                                'action': 'search_retailers',
                                'productsIds': productsIds.toArray(),
                                'lat': latitude,
                                'lon': longitude
                            },
                            function (response) {

                                if (response.length <= 2) {
                                    document.location.reload(true);
                                } else {
                                    setCookie('retailers_location', response, 2);
                                    localStorage.setItem('retailers_location', response);
                                    document.location.reload(true);
                                }
                                $('.reload-spinner').removeClass('active');
                            }
                        );
                    } else {
                        document.location.reload(true);
                    }

                }

            });
        }
    });

    $('body').click(function (event) {
        if (!$(event.target).closest('.button-phone').length && !$(event.target).is('.button-phone')) {
            $(document).find('.popover').css({'display': 'none'});
            $(document).find('.popover-button').removeClass('popover-button');
        }
    });


    $(document).on('click', '.button-phone', function () {

        $(document).find('.popover').css({'display': 'none'});
        $(document).find('.popover-button').removeClass('popover-button');

        const that = $(this);
        const geocoder = new google.maps.Geocoder;
        const latitude = parseFloat($(this).attr('data-id-lat'));
        const longitude = parseFloat($(this).attr('data-id-lon'));
        const latlng = {lat: latitude, lng: longitude};

        geocoder.geocode({'location': latlng}, function (results, status) {
            if (status === google.maps.GeocoderStatus.OK) {
                if (results[1]) {
                    const placeId = results[1].place_id;

                    const map = new google.maps.Map(document.getElementById('map1'), {
                        center: new google.maps.LatLng(0, 0),
                        zoom: 15
                    });

                    const service = new google.maps.places.PlacesService(map);

                    service.getDetails({
                        placeId: placeId
                    }, function (place, status) {
                        if (status === google.maps.places.PlacesServiceStatus.OK) {
                            let phoneNumber = place.formatted_phone_number;
                            if (phoneNumber === undefined) {
                                phoneNumber = 'No phone number';
                            }

                            that.next().addClass('popover-button');
                            that.find('.popover').css({'display': 'block'}).text(phoneNumber)
                        }
                    });

                }
            } else {
                window.alert('Geocoder failed due to: ' + status);
            }
        });

    })
});


function setInformation(text, className = 'error') {
    $('.where-buy-info').addClass(className).text(text);
    setTimeout(() => {
        $('.where-buy-info').text('').removeClass(className)
    }, 3000);
}

function setCookie(name, value, days) {
    let expires = "";
    if (days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "") + expires + "; path=/";
}

function getCookie(name) {

    var matches = document.cookie.match(new RegExp(
        "(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
    ));
    return matches ? decodeURIComponent(matches[1]) : undefined
}

