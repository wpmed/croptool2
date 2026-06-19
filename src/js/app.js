'use strict';

// Declare app level module which depends on filters, and services
angular.module('croptool', ['LocalStorageModule', 'ngSanitize', 'ui.bootstrap', 'angular-ladda', 'pascalprecht.translate']).

config(['$translateProvider', function($translateProvider) {
    $translateProvider.useSanitizeValueStrategy('escapeParameters');
    $translateProvider.useStaticFilesLoader({
        prefix: 'i18n/',
        suffix: '.json'
    });
    $translateProvider.fallbackLanguage('en');
    $translateProvider.preferredLanguage('en');
}]).

service('LoginService', ['$http', '$rootScope', function($http, $rootScope) {

    // console.log('Init LoginService');

    var that = this;

    this.checkLogin = function(res) {
        var data = res.data;
        if (data.user) {
            that.user = {
                name: data.user,
                language: data.language
            };
        } else {
            that.user = undefined;
        }
        that.loginResponse = data;
        if (typeof that.loginResponse != 'object') {
            that.loginResponse = {
                error: 'The CropTool2 backend is currently having problems.',
            };
        }
        that.loginResponse.code = res.status;
        $rootScope.$broadcast('loginStatusChanged', that.loginResponse);
    };

    $http.get('./api/auth/user')
      .then(this.checkLogin, this.checkLogin);

}]).


service('WindowService', ['$rootScope', '$window', function($rootScope, $window) {

    var windowWidth = $window.outerWidth;
    angular.element($window).bind('resize',function() {
        $rootScope.$broadcast('windowWidthChanged', { oldValue: windowWidth, value: $window.outerWidth });
        windowWidth = $window.outerWidth;
        $rootScope.$apply();
     });

}]).

controller('LoginCtrl', ['$scope', '$http', '$httpParamSerializer', 'LoginService', function($scope, $http, $httpParamSerializer, LoginService) {

    $scope.user = LoginService.user;
    $scope.ready = false;

    $scope.oauthLogin = function() {
        window.location.href = './api/auth/login?' + $httpParamSerializer($scope.currentUrlParams);
    };

    $scope.logout = function() {
        $http.get('./api/auth/logout')
        .then(function(response) {
            LoginService.checkLogin(response.data);
            $scope.user = LoginService.user;
        });
    };

    $scope.$on('loginStatusChanged', function() {

        $scope.user = LoginService.user;
        $scope.ready = true;
        if (LoginService.loginResponse.error) {
            var err = LoginService.loginResponse.code == 401
                ? null
                : LoginService.loginResponse.code + ' ' + LoginService.loginResponse.error;
            $scope.oauthError = err;
        }

        $scope.oauthWarnings = LoginService.loginResponse.warnings;

    });

    // console.log('Init LoginCtrl');

}]).

directive('ctCropper', ['$timeout', function($timeout) {
    return {
        scope: {
            onCrop: '&',
            aspectRatio: '@',
            rotation: '@'
        },
        link: function(scope, element) {
            var layoutRetry,
                layoutRetryCount = 0;
            element.on('load', function() {
                layoutRetryCount = 0;
                $timeout(initCropper);
            });
            element.bind('$destroy', destroy);
            scope.$watch('rotation', rotationChanged);
            scope.$on('crop-aspect-ratio-changed', aspectRatioChanged);
            scope.$on('crop-input-changed', cropInputChanged);

            function initCropper() {
                destroyCropper();
                // SVG files might not contain an explicit width/height in
                // which case the size of the browsers viewport is used.
                // Enforce that we always use the server's calculation for image width/height.
                Object.defineProperty(
                        element[0],
                        'naturalWidth',
                        { value: element[0].getAttribute( 'width' ) }
                );
                Object.defineProperty(
                        element[0],
                        'naturalHeight',
                        { value: element[0].getAttribute( 'height' ) }
                );
                scope.cropper = new Cropper(element[0], {
                    aspectRatio: scope.aspectRatio,
                    crop: cropperCrop,
                    autoCropArea: 0.8,
                    dragMode: window.matchMedia('(pointer: coarse), (max-width: 600px)').matches ? 'move' : 'crop',
                    minCropBoxWidth: 44,
                    minCropBoxHeight: 44,
                    responsive: true,
                    restore: true,
                    toggleDragModeOnDblclick: false,
                    wheelZoomRatio: 0.05,

                    // Needed to apply filters when re-initializing cropper.
                    ready: function() {
                        scope.$emit('cropper-ready');
                    },

                    // restrict cropbox to size of canvas, and restrict canvas
                    // to fit within container
                    viewMode: 2,
                });
                scheduleLayoutRetry();
            }
            function scheduleLayoutRetry() {
                if (layoutRetryCount >= 1) {
                    return;
                }
                if (layoutRetry) {
                    $timeout.cancel(layoutRetry);
                }
                layoutRetry = $timeout(function() {
                    var container = element.parent()[0],
                        imageWidth = element[0].clientWidth,
                        containerWidth = container ? container.clientWidth : imageWidth;

                    layoutRetry = null;
                    if (scope.cropper && containerWidth > 0 && imageWidth > 0 && imageWidth < containerWidth * 0.75) {
                        layoutRetryCount += 1;
                        initCropper();
                    }
                }, 150);
            }
            function cropperCrop($event) {
                if (angular.isFunction(scope.onCrop)) {
                    scope.$applyAsync(function() {
                        scope.onCrop({$event: $event});
                    });
                }
            }
            function aspectRatioChanged(_event, change) {
                if (scope.cropper) {
                    var data = scope.cropper.getData(),
                        aspectRatio = change.ratio,
                        mode = change.mode || 'default';

                    scope.cropper.setAspectRatio(aspectRatio);
                    if (mode == 'preserve' && data && data.width && data.height) {
                        scope.cropper.setData(data);
                    } else if (mode == 'preserve-width' && aspectRatio && data && data.width) {
                        data.height = data.width / aspectRatio;
                        scope.cropper.setData(data);
                    }
                }
            }
            function rotationChanged(rotation) {
                if (scope.cropper) {
                    scope.cropper.rotateTo(rotation);
                }
            }
            function cropInputChanged(_event, inputData) {
                if (inputData && typeof inputData === 'object') {
                    var data = scope.cropper.getData();
                    if (inputData.left !== undefined) {
                        data.x = inputData.left;
                    }
                    if (inputData.top !== undefined) {
                        data.y = inputData.top;
                    }
                    if (inputData.width !== undefined) {
                        data.width = inputData.width;
                    }
                    if (inputData.height !== undefined) {
                        data.height = inputData.height;
                    }
                    scope.cropper.setData(data);
                }
            }
            function destroy() {
                if (layoutRetry) {
                    $timeout.cancel(layoutRetry);
                    layoutRetry = null;
                }
                destroyCropper();
            }
            function destroyCropper() {
                if (scope.cropper) {
                    scope.cropper.destroy();
                    scope.cropper = null;
                }
            }
        }
    };
}]).

controller('AppCtrl', ['$scope', '$http', '$timeout', '$q', '$window', '$httpParamSerializer', '$translate', 'LoginService', 'localStorageService', 'WindowService', function($scope, $http, $timeout, $q, $window, $httpParamSerializer, $translate, LoginService, LocalStorageService, WindowService) {

    var everPushedSomething = false,
        pixelratio = [1,1],
        setSelectCalled = false,
        labelFallbackLanguages = ['en', 'mul', 'de', 'fr', 'nl', 'es'],
        descriptionFallbackLanguages = ['en', 'de', 'fr', 'nl', 'es'];

    $scope.availableLanguages = [
        { code: 'en', label: 'English' },
        { code: 'nl', label: 'Nederlands' },
        { code: 'fr', label: 'Francais' },
        { code: 'de', label: 'Deutsch' },
        { code: 'es', label: 'Espanol' }
    ];
    var storedLanguage = LocalStorageService.get('croptool-language');
    $scope.currentLanguage = storedLanguage || 'en';
    useInterfaceLanguage($scope.currentLanguage);
    $scope.changeLanguage = function(language) {
        useInterfaceLanguage(language);
        storedLanguage = language;
        LocalStorageService.set('croptool-language', language);
        applyMetadataLanguage();
    };

    function useInterfaceLanguage(language) {
        $scope.currentLanguage = language;
        $translate.use(language);
    }

    function languageAvailable(language) {
        return $scope.availableLanguages.some(function(option) {
            return option.code == language;
        });
    }

    function useUserLanguageIfUnset() {
        if (!storedLanguage && LoginService.user && languageAvailable(LoginService.user.language)) {
            useInterfaceLanguage(LoginService.user.language);
            applyMetadataLanguage();
        }
    }

    function commonsDocumentationUrl(page) {
        var suffix = $scope.currentLanguage == 'en' ? '' : '/' + $scope.currentLanguage;

        return '//commons.wikimedia.org/wiki/' + page + suffix;
    }

    $scope.commonsCropToolUrl = function() {
        return commonsDocumentationUrl('Commons:CropTool');
    };

    $scope.commonsOverwriteUrl = function() {
        return commonsDocumentationUrl('Commons:Overwriting_existing_files');
    };

    $scope.existingFileUrl = function() {
        if (!$scope.currentUrlParams.site || !$scope.newTitle) {
            return '';
        }

        return '//' + $scope.currentUrlParams.site + '/wiki/File:' + encodeURIComponent($scope.newTitle.replace(/ /g, '_'));
    };

    $scope.currentUrlParams = {};
    /*.site = '';     // Site-part of the URL
    $scope.currentUrlParams.title = '';    // Title-part of the URL
*/
    function getParameterByName(name, source) {
        name = name.replace(/[\[]/, "\\\[").replace(/[\]]/, "\\\]");
        var regex = new RegExp("[\\?&]" + name + "=([^&#]*)"),
            results = regex.exec(source ? source : location.search);
        return results == null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
    }

    function translatedError(message) {
        var translated;
        if (!message) {
            return message;
        }
        translated = $translate.instant(message);
        return translated == message ? message : translated;
    }

    function responseError(data) {
        return translatedError(data?.exception?.[0]?.message ?? data.error);
    }

    function localizedTerm(term, kind) {
        var values = term && term[kind],
            fallbacks = localizedTermFallbacks(kind),
            i,
            language;

        if (!values) {
            return null;
        }
        for (i = 0; i < fallbacks.length; i++) {
            language = fallbacks[i];
            if (values[language]) {
                return values[language];
            }
        }
        return null;
    }

    function localizedTermFallbacks(kind) {
        var fallbacks = kind == 'descriptions' ? descriptionFallbackLanguages : labelFallbackLanguages;
        return [$scope.currentLanguage].concat(fallbacks).filter(function(language, index, languages) {
            return language && languages.indexOf(language) == index;
        });
    }

    function applyMetadataLanguage() {
        var depicts;
        if (!$scope.cropresults || !$scope.cropresults.page || !$scope.cropresults.page.metadata) {
            return;
        }
        depicts = $scope.cropresults.page.metadata.depicts || [];
        depicts.forEach(function(item) {
            item.label = localizedTerm(item, 'labels') || item.label || item.id;
            item.description = localizedTerm(item, 'descriptions');
        });
    }

    $scope.updateCoords = function(c) {
        if (setSelectCalled) {
            // If this call was triggered by a change in scope.crop_dim (by the user),
            // we should not update scope.crop_dim now, since we don't want to get into
            // a recursive update loop!
            setSelectCalled = false;
            return;
        }

        var new_size = [
            Math.round(c.width * pixelratio[0]),
            Math.round(c.height * pixelratio[1])
        ];
        var new_offset = [
            Math.round(c.x * pixelratio[0]),
            Math.round(c.y * pixelratio[1])
        ];

        if (!$scope.metadata) { return; }

        $scope.crop_dim = {
            x: new_offset[0],
            y: new_offset[1],
            w: new_size[0],
            h: new_size[1],
            right: $scope.metadata.original.width - new_offset[0] - new_size[0],
            bottom: $scope.metadata.original.height - new_offset[1] - new_size[1],
            rotate: c.rotate
        };

        syncUnlockedAspectRatioFields();
    }

    //LocalStorageService.setPrefix('croptool');
    $scope.showNotice = !LocalStorageService.get('croptool-notice-4');

    $scope.dismissNotice = function() {
        LocalStorageService.add('croptool-notice-4','hide');
        $scope.showNotice = false;
    }

    $scope.back = function() {
        $scope.cropresults = undefined;
        $scope.error = '';
    };

    $scope.pageChanged = function() {
        $scope.openFile();
    };

    $scope.onCropDimChange = function(current_coord) {

        var ratio = getAspectRatio();
        if (!normalizeCropDimensions()) {
            return;
        }

        if (ratio != 0) {
            if (current_coord == 'w') {
                $scope.crop_dim.h = Math.round($scope.crop_dim.w / ratio);
            } else if (current_coord == 'h') {
                $scope.crop_dim.w = Math.round($scope.crop_dim.h * ratio);
            }
        }

        clampCropDimensions(current_coord, ratio);

        if ($scope.crop_dim.x === undefined || $scope.crop_dim.y === undefined || $scope.crop_dim.w === undefined || $scope.crop_dim.h === undefined) {
            return;
        }

        updateCropEdges();
        syncUnlockedAspectRatioFields();

        setSelectCalled = true; // let updateCoords know we did this
        var cropInput = {
            left: $scope.crop_dim.x / pixelratio[0],
            top: $scope.crop_dim.y / pixelratio[1]
        };
        if (current_coord != 'x' && current_coord != 'y') {
            cropInput.width = $scope.crop_dim.w / pixelratio[0];
            cropInput.height = $scope.crop_dim.h / pixelratio[1];
        }
        $scope.$broadcast('crop-input-changed', cropInput);

    };

    function normalizeCropDimensions() {
        if (!$scope.crop_dim) {
            return false;
        }

        ['x', 'y', 'w', 'h'].forEach(function(key) {
            if ($scope.crop_dim[key] !== undefined && $scope.crop_dim[key] !== null && $scope.crop_dim[key] !== '') {
                $scope.crop_dim[key] = Math.round(parseFloat($scope.crop_dim[key]));
            }
        });

        return !isNaN($scope.crop_dim.x) && !isNaN($scope.crop_dim.y) &&
            !isNaN($scope.crop_dim.w) && !isNaN($scope.crop_dim.h) &&
            $scope.crop_dim.w > 0 && $scope.crop_dim.h > 0;
    }

    function clampCropDimensions(current_coord, ratio) {
        if (!$scope.metadata || !$scope.metadata.original || !$scope.crop_dim) {
            return;
        }

        var maxImageWidth = $scope.metadata.original.width,
            maxImageHeight = $scope.metadata.original.height;

        $scope.crop_dim.x = Math.max(0, Math.min($scope.crop_dim.x, maxImageWidth - 1));
        $scope.crop_dim.y = Math.max(0, Math.min($scope.crop_dim.y, maxImageHeight - 1));

        var maxWidth = maxImageWidth - $scope.crop_dim.x,
            maxHeight = maxImageHeight - $scope.crop_dim.y;

        if (ratio != 0 && current_coord == 'h') {
            $scope.crop_dim.h = Math.min($scope.crop_dim.h, maxHeight);
            $scope.crop_dim.w = Math.round($scope.crop_dim.h * ratio);
            if ($scope.crop_dim.w > maxWidth) {
                $scope.crop_dim.w = maxWidth;
                $scope.crop_dim.h = Math.round($scope.crop_dim.w / ratio);
            }
        } else if (ratio != 0 && current_coord == 'w') {
            $scope.crop_dim.w = Math.min($scope.crop_dim.w, maxWidth);
            $scope.crop_dim.h = Math.round($scope.crop_dim.w / ratio);
            if ($scope.crop_dim.h > maxHeight) {
                $scope.crop_dim.h = maxHeight;
                $scope.crop_dim.w = Math.round($scope.crop_dim.h * ratio);
            }
        } else {
            $scope.crop_dim.w = Math.min($scope.crop_dim.w, maxWidth);
            $scope.crop_dim.h = Math.min($scope.crop_dim.h, maxHeight);
        }

        $scope.crop_dim.w = Math.max(1, $scope.crop_dim.w);
        $scope.crop_dim.h = Math.max(1, $scope.crop_dim.h);
    }

    function updateCropEdges() {
        if (!$scope.metadata || !$scope.metadata.original || !$scope.crop_dim) {
            return;
        }
        $scope.crop_dim.right = $scope.metadata.original.width - $scope.crop_dim.x - $scope.crop_dim.w;
        $scope.crop_dim.bottom = $scope.metadata.original.height - $scope.crop_dim.y - $scope.crop_dim.h;
    }

    $scope.$on('loginStatusChanged', function() {

        if (LoginService.user) {
            // console.log('[AppCtrl] Logged in as ' + LoginService.user.name);
        }

        $scope.status = '';
        $scope.user = LoginService.user;
        useUserLanguageIfUnset();

    });

    function fetchImage() {

        // console.log('[fetchImage] Site: ' + $scope.currentUrlParams.site + ', title: ' + $scope.currentUrlParams.title + ', page: ' + $scope.currentUrlParams.page);

        if (!$scope.currentUrlParams.title) {
            // console.log('[fetchImage] No title given, nothing to fetch');
            return;
        }

        // Reset
        $scope.error = '';
        $scope.busy = true;
        $scope.crop_dim = undefined;
        if ($scope.preRotationCropmethod) {
            $scope.cropmethod = $scope.preRotationCropmethod;
        }
        $scope.rotation = {angle: 0, rightAngle: 0, straightenAngle: 0};
        $scope.preRotationCropmethod = null;
        $scope.filters = {brightness: 0, contrast: 0, saturation: 0};

        $http.get('./api/file/info?' + $httpParamSerializer({
            title: $scope.currentUrlParams.title,
            site: $scope.currentUrlParams.site,
            page: $scope.currentUrlParams.page,
        }))
        .then(function(res) {

            $scope.busy = false;

            var response = res.data;

            if (response.error) {
                $scope.error = translatedError(response.error);
                $scope.metadata = null;
                return;
            }
            if ( res.data?.exception?.[0]?.message ) {
                $scope.error = responseError(res.data);
                $scope.metadata = null;
                return;
            }

            $scope.metadata = response;
            if (!$scope.currentUrlParams.ratio) {
                $scope.aspectratio = 'free';
                $scope.aspectratio_value = null;
                $scope.selectedAspectRatioPreset = null;
                setAspectRatioFields(response.original.width, response.original.height);
            } else if ($scope.currentUrlParams.ratio == 'keep') {
                $scope.aspectRatioPresetChanged('keep');
            } else if ($scope.currentUrlParams.ratio == 'square') {
                $scope.aspectRatioPresetChanged('square');
            }

            if ($scope.currentUrlParams.left !== '' || $scope.currentUrlParams.right !== '' || $scope.currentUrlParams.ratio !== '') {
                setTimeout(function () {
                    var crop_dim = {
                        x: 0,
                        y: 0,
                        w: 100,
                        h: 100,
                    };
                    if ($scope.currentUrlParams.left !== '' && $scope.currentUrlParams.right !== '') {
                        crop_dim.x = + $scope.currentUrlParams.left;
                        crop_dim.w = + response.original.width - $scope.currentUrlParams.right - $scope.currentUrlParams.left;
                    } else if ($scope.currentUrlParams.left !== '') {
                        crop_dim.x = + $scope.currentUrlParams.left;
                        crop_dim.w = + $scope.currentUrlParams.width;
                    } else if ($scope.currentUrlParams.right !== '') {
                        crop_dim.x = + response.original.width - $scope.currentUrlParams.right - $scope.currentUrlParams.width;
                        crop_dim.w = + $scope.currentUrlParams.width;
                    }
                    if ($scope.currentUrlParams.top !== '' && $scope.currentUrlParams.bottom !== '') {
                        crop_dim.y = + $scope.currentUrlParams.top;
                        crop_dim.h = + response.original.height - $scope.currentUrlParams.bottom - $scope.currentUrlParams.top;
                    } else if ($scope.currentUrlParams.top !== '') {
                        crop_dim.y = + $scope.currentUrlParams.top;
                        crop_dim.h = + $scope.currentUrlParams.height;
                    } else if ($scope.currentUrlParams.bottom !== '') {
                        crop_dim.y = + response.original.height - $scope.currentUrlParams.bottom - $scope.currentUrlParams.top;
                        crop_dim.h = + $scope.currentUrlParams.height;
                    }

                    $scope.crop_dim = crop_dim;
                    if (typeof $scope.currentUrlParams.ratio === 'string' && $scope.currentUrlParams.ratio !== '' && $scope.currentUrlParams.ratio.split(':').length == 2) {
                        var parts = $scope.currentUrlParams.ratio.split(':');
                        $scope.aspectratio = 'fixed';
                        $scope.aspectratio_cx = parts[0];
                        $scope.aspectratio_cy = parts[1];
                        $scope.aspectratio_value = parseInt(parts[0]) / parseInt(parts[1]);
                        $scope.selectedAspectRatioPreset = parts[0] + ':' + parts[1];
                    } else {
                        $scope.aspectratio = $scope.currentUrlParams.ratio || 'free';
                        if ($scope.aspectratio == 'free') {
                            $scope.aspectratio_value = null;
                            setAspectRatioFields(response.original.width, response.original.height);
                        } else if ($scope.aspectratio == 'keep') {
                            $scope.aspectRatioPresetChanged('keep');
                        } else if ($scope.aspectratio == 'square') {
                            $scope.aspectRatioPresetChanged('square');
                        }
                    }
                    $scope.onCropDimChange();

                    // If aspect ratio is "keep", we need to find that aspect ratio
                    // now that we have the coordinates of the image file.
                    $scope.aspectRatioChanged();

                }, 300);
            }

            // If aspect ratio is "keep", we need to find that aspect ratio
            // now that we have the coordinates of the image file.
            $scope.aspectRatioChanged();

            $scope.availablePages = [];
            for (var i = 1; i <= $scope.metadata.pagecount; i++) {
                $scope.availablePages.push(i);
            }

            if ($scope.metadata.thumb) {
                pixelratio = [$scope.metadata.original.width/$scope.metadata.thumb.width, $scope.metadata.original.height/$scope.metadata.thumb.height];
            } else {
                pixelratio = [1,1];
            }

            if (!response.error) {

                var p = $scope.currentUrlParams.title.lastIndexOf('.');
                var cropText;
		var ext = $scope.currentUrlParams.title.substr(p);
                if ( $scope.metadata.overrideResultExtension ) {
                    ext = '.' + $scope.metadata.overrideResultExtension
                }
                if ($scope.currentUrlParams.page && $scope.metadata.pagecount > 1) {
                    cropText = ' (page ' + $scope.currentUrlParams.page + ' crop)';
                } else {
                    cropText = ' (cropped)';
                }
                $scope.suggestedNewTitle = $scope.currentUrlParams.title.substr(0, p) + cropText + ext;
                $scope.newTitle = $scope.suggestedNewTitle;
            }

        }, function(res) {
            $scope.metadata = null;
            $scope.error = responseError(res.data);
            $scope.busy = false;
        });
    }

    $scope.locateBorder = function() {
        if ($scope.borderLocatorBusy) return;

        $scope.borderLocatorBusy = true;
        $http.get('./api/file/autodetect?' + $httpParamSerializer({
            title: $scope.currentUrlParams.title,
            site: $scope.currentUrlParams.site,
            page: $scope.currentUrlParams.page
        }))
        .then(function(res) {
            var response = res.data;
            $scope.borderLocatorBusy = false;
            console.log(response);
            var area = response.area;
            $scope.$broadcast('crop-input-changed', {
                left: area[0] / pixelratio[0],
                top: area[1] / pixelratio[1],
                width: (area[2] - area[0]) / pixelratio[0],
                height: (area[3] - area[1]) / pixelratio[1]
            });
        }, function(res) {
            $scope.error = 'An error occurred: ' + res.status + ' ' +
                responseError(res.data);
            $scope.borderLocatorBusy = false;
        });
    };

    $scope.autoStraighten = function() {
        if ($scope.autoStraightenBusy || !$scope.metadata || !$scope.metadata.supportsRotation) return;

        $scope.autoStraightenBusy = true;
        $http.get('./api/file/autostraighten?' + $httpParamSerializer({
            title: $scope.currentUrlParams.title,
            site: $scope.currentUrlParams.site,
            page: $scope.currentUrlParams.page
        }))
        .then(function(res) {
            $scope.rotation.straightenAngle = res.data.angle || 0;
            $scope.autoStraightenBusy = false;
            updateRotationAngle();
        }, function(res) {
            $scope.error = 'An error occurred: ' + res.status + ' ' +
                responseError(res.data);
            $scope.autoStraightenBusy = false;
        });
    };

    $scope.cropMethodChanged = function() {
        if ($scope.straightenLocksCropMethod()) {
            $scope.cropmethod = 'precise';
            return;
        }
        if ($scope.cropmethod === 'lossless') {
            $scope.resetFilters();
        }
        LocalStorageService.set('croptool-cropmethod', $scope.cropmethod);
    };

    function normalizedRightRotation() {
        var angle = Math.round(($scope.rotation && $scope.rotation.rightAngle || 0) / 90) * 90;
        while (angle < 0) {
            angle += 360;
        }
        return angle % 360;
    }

    function straightenAngle() {
        var angle = parseFloat($scope.rotation && $scope.rotation.straightenAngle);
        return isNaN(angle) ? 0 : angle;
    }

    function updateRotationAngle() {
        $scope.rotation.rightAngle = normalizedRightRotation();
        $scope.rotation.straightenAngle = straightenAngle();
        $scope.rotation.angle = $scope.rotation.rightAngle + $scope.rotation.straightenAngle;
        if ($scope.crop_dim) {
            $scope.crop_dim.rotate = $scope.rotation.angle;
        }
        applyRotationCropMethodLock();
    }

    function applyRotationCropMethodLock() {
        if ($scope.straightenLocksCropMethod()) {
            if ($scope.cropmethod !== 'precise') {
                $scope.preRotationCropmethod = $scope.cropmethod;
            }
            $scope.cropmethod = 'precise';
        } else if ($scope.preRotationCropmethod) {
            $scope.cropmethod = $scope.preRotationCropmethod;
            $scope.preRotationCropmethod = null;
            LocalStorageService.set('croptool-cropmethod', $scope.cropmethod);
        }
    }

    $scope.straightenActive = function() {
        return Math.abs(straightenAngle()) >= 0.05;
    };

    $scope.straightenLocksCropMethod = function() {
        return $scope.metadata && $scope.metadata.mime == 'image/jpeg' && $scope.straightenActive();
    };

    $scope.orientationActive = function() {
        return normalizedRightRotation() !== 0 || $scope.straightenActive();
    };

    $scope.previewWidth = function() {
        if (!$scope.cropresults) {
            return null;
        }

        return ($scope.cropresults.thumb ? $scope.cropresults.thumb.width : $scope.cropresults.crop.width);
    };

    $scope.previewBoxStyle = function() {
        var width = $scope.previewWidth();

        if (!width) {
            return {};
        }

        return {
            width: width + 'px',
            marginLeft: 'auto',
            marginRight: 'auto'
        };
    };

    $scope.previewWidthStyle = function() {
        var width = $scope.previewWidth();

        if (!width) {
            return {};
        }

        return {
            width: width + 'px'
        };
    };

    $scope.cropPreviewUrl = function() {
        if (!$scope.cropresults) {
            return '';
        }

        return (($scope.cropresults.thumb ? $scope.cropresults.thumb.name : $scope.cropresults.crop.name) + '?ts=' + $scope.cropresults.time);
    };

    $scope.rotateLeft = function() {
        if (!$scope.metadata || !$scope.metadata.supportsRotation) {
            return;
        }
        $scope.rotation.rightAngle = (normalizedRightRotation() + 270) % 360;
        updateRotationAngle();
    };

    $scope.rotateRight = function() {
        if (!$scope.metadata || !$scope.metadata.supportsRotation) {
            return;
        }
        $scope.rotation.rightAngle = (normalizedRightRotation() + 90) % 360;
        updateRotationAngle();
    };

    $scope.straightenChanged = function() {
        updateRotationAngle();
    };

    $scope.stepCropDimension = function(dimension, step) {
        if (!$scope.crop_dim || !Object.prototype.hasOwnProperty.call($scope.crop_dim, dimension)) {
            return;
        }
        var value = parseInt($scope.crop_dim[dimension]);
        $scope.crop_dim[dimension] = (isNaN(value) ? 0 : value) + step;
        $scope.onCropDimChange(dimension);
    };

    $scope.stepStraighten = function(step) {
        var angle = Math.round((straightenAngle() + step) * 10) / 10;
        $scope.rotation.straightenAngle = Math.max(-15, Math.min(15, angle));
        updateRotationAngle();
    };

    $scope.resetStraighten = function() {
        $scope.rotation.straightenAngle = 0;
        updateRotationAngle();
    };

    $scope.resetOrientation = function() {
        $scope.rotation.rightAngle = 0;
        $scope.rotation.straightenAngle = 0;
        updateRotationAngle();
    };

    function getAspectRatio() {
        var ratio = 0;
        if ($scope.aspectratio == 'fixed') {
            var cx = parseInt($scope.aspectratio_cx),
                cy = parseInt($scope.aspectratio_cy);
            if (!cx || cx < 0 || !cy || cy < 0) {
                // TODO: Indicate invalid state by css class
                return null;
            }
            ratio = $scope.aspectratio_value || cx / cy;
        }
        return ratio;
    }

    function setAspectRatioFields(width, height, keepExact) {
        width = parseInt(width);
        height = parseInt(height);
        if (!width || !height) {
            return;
        }
        if (keepExact) {
            $scope.aspectratio_value = width / height;
        }
        var approximated = approximateAspectRatio(width, height);
        width = approximated[0];
        height = approximated[1];
        var divisor = greatestCommonDivisor(width, height);
        $scope.aspectratio_cx = width / divisor;
        $scope.aspectratio_cy = height / divisor;
    }

    function syncUnlockedAspectRatioFields() {
        if ($scope.aspectratio != 'free' || !$scope.crop_dim) {
            return;
        }
        $scope.aspectratio_value = $scope.crop_dim.w / $scope.crop_dim.h;
        setAspectRatioFields($scope.crop_dim.w, $scope.crop_dim.h);
    }

    function approximateAspectRatio(width, height) {
        var ratio = width / height,
            maxTerm = 20,
            bestWidth = 1,
            bestHeight = 1,
            bestError = Math.abs(ratio - 1);

        for (var candidateHeight = 1; candidateHeight <= maxTerm; candidateHeight++) {
            var candidateWidth = Math.max(1, Math.round(ratio * candidateHeight));
            if (candidateWidth > maxTerm) {
                continue;
            }
            var error = Math.abs(ratio - candidateWidth / candidateHeight);
            if (error < bestError) {
                bestWidth = candidateWidth;
                bestHeight = candidateHeight;
                bestError = error;
            }
        }

        return [bestWidth, bestHeight];
    }

    function currentAspectRatioValue() {
        if ($scope.aspectratio_value) {
            return $scope.aspectratio_value;
        }
        var cx = parseInt($scope.aspectratio_cx),
            cy = parseInt($scope.aspectratio_cy);
        if (!cx || !cy) {
            return null;
        }
        return cx / cy;
    }

    function originalAspectRatioValue() {
        if (!$scope.metadata || !$scope.metadata.original) {
            return null;
        }
        return $scope.metadata.original.width / $scope.metadata.original.height;
    }

    $scope.isOriginalAspectRatio = function() {
        var current = currentAspectRatioValue(),
            original = originalAspectRatioValue();
        return $scope.aspectratio != 'free' &&
            ($scope.selectedAspectRatioPreset == 'keep' || !$scope.selectedAspectRatioPreset) &&
            current !== null &&
            original !== null &&
            Math.abs(current - original) < 0.01;
    };

    $scope.isAspectRatioPreset = function(width, height) {
        var key = width + ':' + height,
            current = currentAspectRatioValue();
        return $scope.aspectratio != 'free' &&
            ($scope.selectedAspectRatioPreset == key || !$scope.selectedAspectRatioPreset) &&
            current !== null &&
            Math.abs(current - width / height) < 0.001;
    };

    function greatestCommonDivisor(a, b) {
        a = Math.abs(a);
        b = Math.abs(b);
        while (b) {
            var next = b;
            b = a % b;
            a = next;
        }
        return a || 1;
    }

    function applyAspectRatioChange(mode) {
        var ratio = getAspectRatio();
        if (ratio === null) {
            return;
        }
        $scope.aspectratio_cxy = ratio;
        $scope.$broadcast('crop-aspect-ratio-changed', {
            ratio: ratio,
            mode: mode || 'default'
        });

        LocalStorageService.set('croptool-aspectratio', $scope.aspectratio);
        LocalStorageService.set('croptool-aspectratio-x', $scope.aspectratio_cx);
        LocalStorageService.set('croptool-aspectratio-y', $scope.aspectratio_cy);
        LocalStorageService.set('croptool-aspectratio-value', $scope.aspectratio_value || '');
    }

    $scope.aspectRatioChanged = function() {
        applyAspectRatioChange('default');
    };

    $scope.aspectRatioPresetChanged = function(preset, width, height) {
        if (preset == 'keep') {
            if ($scope.metadata && $scope.metadata.original) {
                setAspectRatioFields($scope.metadata.original.width, $scope.metadata.original.height, true);
            }
            $scope.selectedAspectRatioPreset = 'keep';
            $scope.aspectratio = 'fixed';
        } else if (preset == 'ratio' || preset == 'square') {
            width = preset == 'square' ? 1 : width;
            height = preset == 'square' ? 1 : height;
            $scope.aspectratio_value = width / height;
            setAspectRatioFields(width, height);
            $scope.selectedAspectRatioPreset = width + ':' + height;
            $scope.aspectratio = 'fixed';
        } else {
            $scope.selectedAspectRatioPreset = null;
            $scope.aspectratio = preset;
        }
        applyAspectRatioChange('preserve-width');
    };

    $scope.aspectRatioFieldsChanged = function() {
        $scope.aspectratio = 'fixed';
        $scope.aspectratio_value = null;
        $scope.selectedAspectRatioPreset = null;
        applyAspectRatioChange('preserve-width');
    };

    $scope.stepAspectRatio = function(dimension, step) {
        var field = dimension == 'x' ? 'aspectratio_cx' : 'aspectratio_cy',
            value = parseInt($scope[field]);
        $scope[field] = Math.max(1, (isNaN(value) ? 1 : value) + step);
        $scope.aspectRatioFieldsChanged();
    };

    $scope.toggleAspectRatioLock = function() {
        if ($scope.aspectratio == 'free') {
            if ($scope.crop_dim) {
                $scope.aspectratio_value = $scope.crop_dim.w / $scope.crop_dim.h;
            }
            $scope.aspectratio = 'fixed';
        } else {
            $scope.aspectratio = 'free';
        }
        $scope.selectedAspectRatioPreset = null;
        applyAspectRatioChange('preserve');
    };

    function parseImageUrlOrTitle( params ) {

        var pattern1 = /([a-z0-9.\-]+)\.(wikimedia.org|wikipedia.org|wmflabs.org|wikisource.org)\/wiki\/([^?]+)/,
            pattern2 = /([a-z0-9.\-]+)\.(wikimedia.org|wikipedia.org|wmflabs.org|wikisource.org)\/w\/index.php/,
            matches1 = params.title.match(pattern1),
            matches2 = params.title.match(pattern2);

        var qs = '?' + params.title.split('?')[1];
        if (matches1) {
            params.site = matches1[1] + '.' + matches1[2];
            params.title = matches1[3];
            params.page = getParameterByName('page', qs) || params.page;
        } else if (matches2) {
            params.site = matches2[1] + '.' + matches2[2];
            params.title = getParameterByName('title', qs);
            params.page = getParameterByName('page', qs);
        } else {
            params.site = params.site || 'commons.wikimedia.org';
            params.page = getParameterByName('page', qs) || params.page;
        }

        try {
            params.title = decodeURIComponent(params.title);
        } catch (e) {
            // Ignore
        }

        params.title = params.title
            .replace(/_/g, ' ')
            .replace(/^[^:]+:/, '');  // Strip off File:, Fil:, etc.

        if (params.title.match(/\.(pdf|djvu|tiff?)$/) && !params.page) {
            params.page = 1;
        }

        return params;
    }


    $scope.openFile = function(updateHistory) {

        if (updateHistory === false) {
            $scope.currentUrlParams = {
                site: getParameterByName('site'),
                title: getParameterByName('title'),
                page: getParameterByName('page'),
                left: getParameterByName('left'),
                top: getParameterByName('top'),
                right: getParameterByName('right'),
                bottom: getParameterByName('bottom'),
                width: getParameterByName('width'),
                height: getParameterByName('height'),
                ratio: getParameterByName('ratio'),
            };
        }

        if (updateHistory !== false) {
            var params = $httpParamSerializer($scope.currentUrlParams);
            var newUrl = location.href.split('?', 1)[0] + (params.length ? '?' + params : '');
            window.history.pushState(null, null, newUrl);
            everPushedSomething = true;
        }

        // Resetting state
        $scope.error = '';
        $scope.newTitle = '';
        $scope.suggestedNewTitle = '';
        $scope.cropresults = null;
        $scope.uploadresults = null;
        $scope.selectedAspectRatioPreset = null;

        if (!$scope.currentUrlParams.title) {
            $scope.metadata = null;
            $scope.currentUrlParams = {};
            return;
        }

        // console.log('   params before parse: ',$scope.currentUrlParams);
        $scope.currentUrlParams = parseImageUrlOrTitle($scope.currentUrlParams);
        // console.log('    params after parse: ',$scope.currentUrlParams);
        if ($scope.currentUrlParams.page) {
            $scope.overwrite = 'rename';  // Force rename
        }


        // $scope.currentUrlParams.site = o.site;
        // $scope.currentUrlParams.title = o.title;
        // $scope.currentUrlParams.page = o.page;

        fetchImage();
    };

    $scope.preview = function() {
        if ($scope.crop_dim === undefined) {
            alert('Please select a crop region then press submit.');
            return false;
        }

        $scope.error = '';
        $scope.allowIgnoreWarnings = false;
        $scope.ignoreWarnings = false;
        $scope.confirmOverwrite = false;
        $scope.confirmOverwriteTouched = false;
        $scope.confirmOverwriteKey = null;
        $scope.ladda = true;
        $scope.overwrite = 'rename';

        $http.get('./api/file/crop?' + $httpParamSerializer({
            title: $scope.currentUrlParams.title,
            site: $scope.currentUrlParams.site,
            page: $scope.currentUrlParams.page,
            language: $scope.currentLanguage,
            method: $scope.cropmethod,
            x: $scope.crop_dim.x,
            y: $scope.crop_dim.y,
            rotate: $scope.crop_dim.rotate,
            width: $scope.crop_dim.w,
            height: $scope.crop_dim.h,
            brightness: $scope.filters.brightness,
            contrast: $scope.filters.contrast,
            saturation: $scope.filters.saturation
        }))
        .then(function(res) {
            var response = res.data;
            $scope.ladda = false;
            if (response.page.hasAssessmentTemplates || response.page.hasDoNotCropTemplate || response.page.hasUploadProtection) {
                $scope.overwrite = "rename";
            }

            // TODO: Add timestamps to invalidate cache!

            $scope.cropresults = response;
            applyMetadataLanguage();
            if (response.page.elems.wikidata) {
                var entityId = response.page.elems['wikidata-item'];
                var entityLabel = response.wikidata.labels.en;
                if (entityLabel) {
                    $scope.cropresults.wikidataLink = '<a target="_blank" href="https://www.wikidata.org/wiki/' + entityId + '">' + entityLabel + ' (' + entityId + ')</a>';
                } else {
                    $scope.cropresults.wikidataLink = '<a target="_blank" href="https://www.wikidata.org/wiki/' + entityId + '">' + entityId + '</a>';
                }
                $scope.overwrite = 'rename';
            }
            syncConfirmOverwriteForNewTitle();
            $scope.updateUploadComment();
        }, function(res) {
            $scope.error = '[Error] ' + responseError(res.data);
            $scope.ladda = false;
        });


    };

    $scope.upload = function(isRetrying) {

        if ($scope.uploadBlockedByFilenameConflict()) {
            $scope.confirmOverwriteTouched = true;
            return false;
        }

        $scope.ladda2 = true;
        $scope.error = '';
        $scope.allowIgnoreWarnings = false;

        var params = {
            title: $scope.currentUrlParams.title,
            site: $scope.currentUrlParams.site,
            page: $scope.currentUrlParams.page,
            overwrite: $scope.overwrite,
            comment: $scope.uploadComment,
            filename: $scope.newTitle,
            elems: $scope.cropresults.page.elems,
            metadata: $scope.cropresults.page.metadata,
            store: true
        };
        if ($scope.overwrite == 'rename') {
            params.metadata = $scope.cropresults.page.metadata;
        }

        if ($scope.ignoreWarnings || ($scope.overwrite == 'rename' && $scope.confirmOverwrite)) {
            params.ignorewarnings = '1';
        }

        $http.post('./api/file/publish', params)
        .then(function(res) {
            var response = res.data;

            // console.log(response);

            $scope.ladda2 = false;
            if (response.result === 'Success') {
                $scope.uploadresults = response; //.imageinfo.descriptionurl;
                $scope.uploadResultFileName = $scope.overwrite == 'rename' ?
                    $scope.newTitle :
                    $scope.currentUrlParams.title;
                $scope.uploadResultUrl = response.imageinfo.descriptionurl;
                $scope.uploadResultCopied = '';

            } else if (response.result == 'Warning') {
                var warnings = Object.keys(response.warnings);

                // Don't allow overwriting other files or pages
                $scope.allowIgnoreWarnings = (warnings.indexOf('exists') == -1 && warnings.indexOf('page-exists') == -1);

                if (warnings.length == 1 && warnings[0] == 'was-deleted') {
                    // This is safe to ignore. Retry right away, but only once
                    if (!isRetrying) {
                        $scope.ignoreWarnings = true;
                        $scope.upload(true);
                    }
                } else {
                    $scope.error = 'Upload failed because of the following warning(s): ' + warnings.join(', ') + '.';
                }

            } else {
                $scope.error = 'Upload failed! ';
                if (response.error) {
                    $scope.error += response.error.info;
                }
            }

        }, function(res) {
            $scope.ladda2 = false;
            $scope.error = 'Upload failed! ' + responseError(res.data);
        });

    };

    $scope.copyUploadResult = function(value, field) {
        var textarea,
            copyPromise;

        if (!value) {
            return;
        }

        function showCopied() {
            $scope.$evalAsync(function() {
                $scope.uploadResultCopied = field;
                $timeout(function() {
                    if ($scope.uploadResultCopied == field) {
                        $scope.uploadResultCopied = '';
                    }
                }, 2000);
            });
        }

        if ($window.navigator.clipboard && $window.navigator.clipboard.writeText) {
            copyPromise = $window.navigator.clipboard.writeText(value);
            copyPromise.then(showCopied, function() {
                fallbackCopy();
            });
            return;
        }

        fallbackCopy();

        function fallbackCopy() {
            textarea = $window.document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            $window.document.body.appendChild(textarea);
            textarea.select();

            try {
                if ($window.document.execCommand('copy')) {
                    showCopied();
                }
            } finally {
                $window.document.body.removeChild(textarea);
            }
        }
    };

    $scope.toggleMetadata = function(item) {
        item.selected = !item.selected;
        $scope.updateUploadComment();
    };

    $scope.confirmOverwriteChanged = function() {
        $scope.confirmOverwriteTouched = true;
        $scope.confirmOverwriteKey = $scope.confirmOverwrite ? newTitleExistsKey() : null;
    };

    function newTitleExistsKey() {
        return $scope.currentUrlParams.site + ':' + $scope.newTitle;
    }

    $scope.uploadBlockedByFilenameConflict = function() {
        return $scope.overwrite == 'rename' && $scope.exists[newTitleExistsKey()] === true && !$scope.confirmOverwrite;
    };

    function syncConfirmOverwriteForNewTitle() {
        var key = newTitleExistsKey(),
            exists = $scope.exists[key];
        if (exists === true) {
            $scope.confirmOverwrite = $scope.confirmOverwriteKey === key;
        } else if (exists === false) {
            $scope.confirmOverwrite = false;
            $scope.confirmOverwriteTouched = false;
            $scope.confirmOverwriteKey = null;
        }
    }

    angular.element($window).bind('popstate', function(e) {

        if (!everPushedSomething) {
            // Chrome and Safari always emit a popstate event on page load, but Firefox doesn't.
            // If we've newer pushed anything, we assume this event was called on page load and ignore it.
            return;
        }
        $scope.$apply(function() {
            $scope.openFile(false);
        });
    });

    $scope.openFile(false);

    $scope.status = 'Checking login';

    // Defaults
    $scope.cropmethod = LocalStorageService.get('croptool-cropmethod') || 'precise';
    $scope.aspectratio = LocalStorageService.get('croptool-aspectratio') || 'free';
    $scope.aspectratio_cx = LocalStorageService.get('croptool-aspectratio-x') || '16';
    $scope.aspectratio_cy = LocalStorageService.get('croptool-aspectratio-y') || '9';;
    $scope.aspectratio_value = parseFloat(LocalStorageService.get('croptool-aspectratio-value')) || null;
    $scope.overwrite = 'rename';
    $scope.rotation = {angle: 0, rightAngle: 0, straightenAngle: 0};
    $scope.preRotationCropmethod = null;
    $scope.filters = {brightness: 0, contrast: 0, saturation: 0};
    $scope.aspectRatioChanged();

    /**
     * Adapted from MagickSafeReciprocal in ImageMagick (MagickCore/statistic-private.h).
     *
     * License: https://imagemagick.org/script/license.php
     */
    function safeReciprocal(value) {
        if (Math.abs(value) < 1e-15) {
            return 1e15;
        }
        return 1.0 / value;
    }

    /**
     * Approximates the brightness/contrast filter used by ImageMagick.
     *
     * This approximation isn't perfect, but it's pretty close. An SVG is used
     * to apply the filter instead of a regular CSS filter since CSS filters
     * are multiplicative, not additive like ImageMagick.
     */
    function updateFilters(brightness, contrast, saturation) {
        var slope = contrast >= 0
            ? 100.0 * safeReciprocal(100.0 - contrast)
            : 0.01 * contrast + 1.0;
        var intercept = (0.01 * brightness - 0.5) * slope + 0.5;

        var transfer = document.querySelector('#crop-filter feComponentTransfer');
        var funcs = transfer ? transfer.children : [];
        for (var i = 0; i < funcs.length; i++) {
            funcs[i].setAttribute('slope', slope);
            funcs[i].setAttribute('intercept', intercept);
        }

        var matrix = document.querySelector('#crop-filter feColorMatrix');
        if (matrix) {
            matrix.setAttribute('values', 1.0 + saturation / 100.0);
        }
    }

    function applyFilters() {
        // Clamping is needed since input validation is only advisory.
        var brightness = Math.max(-100, Math.min(100, $scope.filters.brightness || 0));
        var contrast = Math.max(-100, Math.min(100, $scope.filters.contrast || 0));
        var saturation = Math.max(-100, Math.min(100, $scope.filters.saturation || 0));
        var filterValue = (brightness || contrast || saturation) ? 'url(#crop-filter)' : '';

        updateFilters(brightness, contrast, saturation);

        var images = document.querySelectorAll('.cropper-crop-box img');
        for (var i = 0; i < images.length; i++) {
            images[i].style.filter = filterValue;
        }
    }

    $scope.filtersActive = function() {
        return !!($scope.filters && (
            $scope.filters.brightness ||
            $scope.filters.contrast ||
            $scope.filters.saturation
        ));
    };

    $scope.filterActive = function(filter) {
        return !!($scope.filters && $scope.filters[filter]);
    };

    $scope.resetFilter = function(filter) {
        if ($scope.filters && Object.prototype.hasOwnProperty.call($scope.filters, filter)) {
            $scope.filters[filter] = 0;
        }
    };

    $scope.stepFilter = function(filter, step) {
        if (!$scope.filters || !Object.prototype.hasOwnProperty.call($scope.filters, filter)) {
            return;
        }
        var value = parseInt($scope.filters[filter]);
        value = isNaN(value) ? 0 : value;
        $scope.filters[filter] = Math.max(-100, Math.min(100, value + step));
    };

    function clampFilterValue(value, min, max) {
        return Math.max(min, Math.min(max, Math.round(value)));
    }

    function currentCropFilterStats() {
        var image = document.querySelector('#cropbox'),
            width,
            height,
            sx,
            sy,
            sw,
            sh,
            canvas,
            ctx,
            data,
            luminanceSum = 0,
            luminanceSquaredSum = 0,
            saturationSum = 0,
            count,
            i,
            r,
            g,
            b,
            max,
            min,
            luminance;

        if (!image || !$scope.crop_dim || !pixelratio[0] || !pixelratio[1]) {
            return null;
        }

        sx = Math.max(0, $scope.crop_dim.x / pixelratio[0]);
        sy = Math.max(0, $scope.crop_dim.y / pixelratio[1]);
        sw = Math.max(1, $scope.crop_dim.w / pixelratio[0]);
        sh = Math.max(1, $scope.crop_dim.h / pixelratio[1]);
        width = Math.max(1, Math.min(80, Math.round(sw)));
        height = Math.max(1, Math.min(80, Math.round(sh)));
        canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        ctx = canvas.getContext('2d');

        try {
            ctx.drawImage(image, sx, sy, sw, sh, 0, 0, width, height);
            data = ctx.getImageData(0, 0, width, height).data;
        } catch (e) {
            return null;
        }

        count = width * height;
        for (i = 0; i < data.length; i += 4) {
            r = data[i] / 255;
            g = data[i + 1] / 255;
            b = data[i + 2] / 255;
            max = Math.max(r, g, b);
            min = Math.min(r, g, b);
            luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;
            luminanceSum += luminance;
            luminanceSquaredSum += luminance * luminance;
            saturationSum += max === 0 ? 0 : (max - min) / max;
        }

        var mean = luminanceSum / count;
        return {
            mean: mean,
            deviation: Math.sqrt(Math.max(0, luminanceSquaredSum / count - mean * mean)),
            saturation: saturationSum / count
        };
    }

    function autoFilterSuggestions() {
        var stats = currentCropFilterStats();
        if (!stats) {
            return null;
        }

        return {
            brightness: clampFilterValue((0.5 - stats.mean) * 70, -25, 25),
            contrast: clampFilterValue((0.24 - stats.deviation) * 120, -10, 30),
            saturation: clampFilterValue((0.28 - stats.saturation) * 70, -10, 25)
        };
    }

    $scope.autoFilter = function(filter) {
        var suggestions = autoFilterSuggestions();
        if (suggestions && Object.prototype.hasOwnProperty.call(suggestions, filter)) {
            $scope.filters[filter] = suggestions[filter];
        }
    };

    $scope.resetFilters = function() {
        $scope.filters = {brightness: 0, contrast: 0, saturation: 0};
    };

    $scope.$watchGroup(['filters.brightness', 'filters.contrast', 'filters.saturation'], applyFilters);
    $scope.$on('cropper-ready', applyFilters);

    // On filename change, check with the MediaWiki API if the file exists.
    // Delay 500 ms before checking in case the user is in the process of typing.
    // Code below might eventually better be separated out into a directive or something.

    var canceler;

    $scope.exists = [];

    function fileExists( site, title ) {

        if (!canceler) {
            //console.log('nothing to abort');
        } else if (canceler && canceler.resolve) {
            // Aborts the $http request if it isn't finished.
            //console.log('abort http');
            canceler.resolve();
        } else {
            // Abort the timer
            // console.log('abort timer');
            $timeout.cancel(canceler);
        }

        if (title == '') {
            canceler = null;
            return;
        }

        canceler = $timeout(function() {

            canceler = $q.defer();

            // console.log('Check existence of site:' + site + ', title:' + title);

            $http.get('./api/file/exists?' + $httpParamSerializer({
                site: site,
                title: title
            }), {
                timeout: canceler.promise,
            }).then(function(res) {
                var response = res.data;
                var key = site + ':' + title;

                $scope.error = translatedError(response.error);

                if (response.error) {
                    $scope.error = translatedError(response.error);
		} else if ( res.data?.exception?.[0]?.message ) {
			$scope.error = responseError(res.data);
                } else {
                    $scope.exists[key] = response.exists;
                    if (key == newTitleExistsKey()) {
                        syncConfirmOverwriteForNewTitle();
                    }
                    // console.log($scope.exists);
                }
                canceler = null;
            });

        }, 300);
    }

    // Check for file existence as you type
    $scope.$watch('titleInput', function() {

        if (!$scope.titleInput) {
            return;
        }

        var params = parseImageUrlOrTitle({title: $scope.titleInput}),
            key = params.site + ':' + params.title;

        if (params.title && $scope.exists[key] === undefined) {
            if ($scope.title !== params.title) {
                $scope.error = '';
                fileExists( params.site, params.title );
            }
        }

        $scope.currentUrlParams = params;
    });

    $scope.updateUploadComment = function() {
        console.log('UPDATE UPLOAD COMM', $scope.cropresults.page.elems);

        // Cropped {x % using CropTool}
        // Removed border by cropping {x % using CropTool}
        // Removed watermark by cropping {x % using CropTool}

        // [[File:X]] cropped x % using CropTool
        // Removed border from [[File:X]] by cropping {x % using CropTool}
        // Removed watermark from [[File:X]] by cropping {x % using CropTool}

        var s = '';
        if ($scope.overwrite == 'rename') {
            s += '[[:File:' + $scope.currentUrlParams.title + ']] cropped';
        } else {
            s += 'Cropped';
        }

        // %s % horizontally, %s % vertically, rotated %s° using [[Commons:CropTool|CropTool2]] with %s mode.
        s += ' ' + $scope.cropresults.dim;

        if ($scope.cropresults.page.elems.border) {
            s += ' Removed border.';
        }
        if ($scope.cropresults.page.elems.trimming) {
            s += ' Image was trimmed.';
        }
        if ($scope.cropresults.page.elems.watermark) {
            s += ' Removed watermark.';
        }
        if ($scope.cropresults.page.elems.wikidata) {
            s += ' Crop for [[:wikidata:' + $scope.cropresults.page.elems['wikidata-item'] + '|Wikidata]].';
        }
        if ($scope.overwrite == 'rename') {
            var omittedCategories = ($scope.cropresults.page.metadata.categories || [])
                .filter(function(category) { return !category.selected; })
                .map(function(category) { return category.name; });
            var omittedDepicts = ($scope.cropresults.page.metadata.depicts || [])
                .filter(function(depicts) { return !depicts.selected; })
                .map(function(depicts) { return depicts.id; });
            if (omittedCategories.length || omittedDepicts.length) {
                var omitted = [];
                if (omittedCategories.length) {
                    omitted.push('categories: ' + omittedCategories.join(', '));
                }
                if (omittedDepicts.length) {
                    omitted.push('depicts: ' + omittedDepicts.join(', '));
                }
                s += ' Omitted metadata (' + omitted.join('; ') + ').';
            }
        }
        $scope.uploadComment = s;
    };

    $scope.$watch('newTitle', function() {

        // TODO: !!! pageExists -> imageUrlOrTitleExists

        if ($scope.newTitle && $scope.exists[$scope.currentUrlParams.site + ':' + $scope.newTitle] === undefined) {
            fileExists($scope.currentUrlParams.site, $scope.newTitle);
        } else if ($scope.newTitle) {
            syncConfirmOverwriteForNewTitle();
        }

    });

    $scope.$on('windowWidthChanged', function(evt, val) {
        //console.log('Width changed');
        //console.log(val);
    });

}]);
