<?php

namespace FluentSupport\App\Services\Blocks;

class BlockHelper
{
    // Store the attributes provided for styling
    private static $attributes = [];

    /**
     * Generate and output inline CSS styles based on the provided attributes.
     *
     * This method processes the provided attributes and dynamically generates CSS styles for various components
     * of customer portal pages, such as the Tickets List, View Ticket, and Create Ticket sections.
     *
     * @param array $attributes An associative array of attributes used for styling.
     */

    public static function processAttributesAndPrepareStyle($attributes)
    {
        self::$attributes = $attributes;
        ?>
        <style type="text/css">
            #fluent_support_client_app {
                /*Tickets List style*/
                .fs_button_groups > .fs_btn_active {
                    border-color: <?php echo esc_attr(self::getPrintableColor('filterButtonActiveBorderColor', '#409eff')); ?>;
                    box-shadow: -1px 0 0 0 <?php echo esc_attr(self::getPrintableColor('filterButtonActiveBorderColor', '#409eff')); ?>;
                    background-color: <?php echo esc_attr(self::getPrintableColor('filterButtonActiveBgColor', '#409eff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('filterButtonActiveTextColor', '#ffffff')); ?>;
                }
                .fst_tickets .fs_all_tickets .fs_tk_header {
                    background-color: <?php echo esc_attr(self::getPrintableColor('allTicketsHeaderBgColor', '#ebeef4')); ?>;
                <?php echo esc_attr(self::getBorderRadiusStyle('allTicketsHeader', $attributes)); ?>
                }
                .fs_btn_all {
                    background-color: <?php echo esc_attr(self::getPrintableColor('filterButtonAllBgColor', '#409eff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('filterButtonAllTextColor', '#ffffff')); ?>;
                    box-shadow: 1px 0 0 0 <?php echo esc_attr(self::getPrintableColor('filterButtonAllBgColor', '#409eff')); ?>;
                <?php echo esc_attr(self::getBorderStyle('filterButtonAllBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('filterButtonAllBorder', $attributes)); ?>
                }
                .fs_btn_open {
                    background-color: <?php echo esc_attr(self::getPrintableColor('filterButtonOpenBgColor', '#ffffff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('filterButtonOpenTextColor', '#606266')); ?>;
                <?php echo esc_attr(self::getBorderStyle('filterButtonOpenBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('filterButtonOpenBorder', $attributes)); ?>
                }
                .fs_btn_closed {
                    background-color: <?php echo esc_attr(self::getPrintableColor('filterButtonClosedBgColor', '#ffffff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('filterButtonClosedTextColor', '#606266')); ?>;
                <?php echo esc_attr(self::getBorderStyle('filterButtonClosedBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('filterButtonClosedBorder', $attributes)); ?>
                }
                .fs_btn_logout {
                    background-color: <?php echo esc_attr(self::getPrintableColor('allTicketsLogoutButtonBgColor', '#f56c6c')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('allTicketsLogoutButtonTextColor', '#ffffff')); ?>;
                <?php echo esc_attr(self::getBorderStyle('allTicketsLogoutButtonBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('allTicketsLogoutButtonBorder', $attributes)); ?>
                }
                .fs_btn_create_ticket {
                    background-color: <?php echo esc_attr(self::getPrintableColor('buttonCreateTicketBgColor', '#67c23a')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('buttonCreateTicketTextColor', '#ffffff')); ?>;
                <?php echo esc_attr(self::getBorderStyle('allTicketsCreateTicketButtonBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('allTicketsCreateTicketButtonBorder', $attributes)); ?>
                }

                .fs_table thead{
                    background-color: <?php echo esc_attr(self::getPrintableColor('allTicketsTableHeaderBgColor', '#f8f8f8')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('allTicketsTableHeaderTextColor', '#646568')); ?>;
                    text-align:<?php echo esc_attr($attributes['allTicketsTableHeaderTextAlign']); ?>;
                }

                .fs_table thead th{
                    background-color: <?php echo esc_attr(self::getPrintableColor('allTicketsTableHeaderBgColor', '#f8f8f8')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('allTicketsTableHeaderTextColor', '#646568')); ?>;
                    text-align:<?php echo esc_attr($attributes['allTicketsTableHeaderTextAlign']); ?>;
                }
                .fst_pagi_wrapper{
                    background-color: <?php echo esc_attr(self::getPrintableColor('allTicketsFooterBgColor', '#ebeef4')); ?>;
                    margin: 0 auto;
                <?php echo esc_attr(self::getBorderRadiusStyle('allTicketsFooter')); ?>
                }

                /*View Ticket style*/
                .fst_client_portal .fs_ticket .fs_tk_header {
                    background-color: <?php echo esc_attr(self::getPrintableColor('viewTicketHeaderStyleBgColor', '#ebeef4')); ?>;
                <?php echo esc_attr(self::getBorderStyle('viewTicketHeader')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('viewTicketHeader')); ?>
                }

                .fst_client_portal .fs_th_header .fs_tk_subject h2 {
                    color: <?php echo esc_attr(self::getPrintableColor('viewTicketHeaderStyleTextColor', '#314351')); ?>;
                }

                .fs_ticket_id {
                    color: <?php echo esc_attr(self::getPrintableColor('viewTicketHeaderIdTextColor', '#93a1b0')); ?>;
                }

                .fs_refresh_button {
                    background-color: <?php echo esc_attr(self::getPrintableColor('refreshButtonBgColor', '#ffffff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('refreshButtonTextColor', '#000000')); ?>;
                <?php echo esc_attr(self::getBorderStyle('refreshButtonBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('refreshButtonBorder', $attributes)); ?>
                }

                .fs_all_button {
                    background-color: <?php echo esc_attr(self::getPrintableColor('allButtonBgColor', '#ffffff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('allButtonTextColor', '#606266')); ?>;
                <?php echo esc_attr(self::getBorderStyle('allButtonBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('allButtonBorder', $attributes)); ?>
                }

                .fs_close_button {
                    background-color: <?php echo esc_attr(self::getPrintableColor('closeTicketButtonBgColor', '#f56c6c')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('closeTicketButtonTextColor', '#ffffff')); ?>;
                <?php echo esc_attr(self::getBorderStyle('closeTicketButtonBorder')); ?>
                <?php echo esc_attr(self::getBorderRadiusStyle('closeTicketButtonBorder', $attributes)); ?>
                }

                .fst_reply_box, .fs_threads_container {
                    background-color: <?php echo esc_attr(self::getPrintableColor('viewTicketPageBodyBgColor', '#ffffff')); ?>;
                }

                .fs_agent {
                    border-left: <?php echo esc_attr($attributes['ribbonSupportStaffTailWidth']); ?>px solid <?php echo esc_attr($attributes['ribbonSupportStaffBgColor']); ?>;
                }

                .fs_conv_type_response .fs_thread_ribbon_agent{
                    background-color: <?php echo esc_attr(self::getPrintableColor('ribbonSupportStaffBgColor', '#1785EB')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('ribbonSupportStaffTextColor', '#ffffff')); ?>;
                    padding: <?php echo esc_attr($attributes['ribbonSupportStaffPaddingTop']); ?>px <?php echo esc_attr($attributes['ribbonSupportStaffPaddingRight']); ?>px <?php echo esc_attr($attributes['ribbonSupportStaffPaddingBottom']); ?>px <?php echo esc_attr($attributes['ribbonSupportStaffPaddingLeft']); ?>px;
                }

                .fs_customer {
                    border-left: <?php echo esc_attr($attributes['viewTicketThreadStarterTailWidth']); ?>px solid <?php echo esc_attr($attributes['viewTicketThreadStarterBgColor']); ?>;
                }

                .fs_conv_type_response .fs_thread_ribbon_customer{
                    background-color: <?php echo esc_attr(self::getPrintableColor('viewTicketThreadStarterBgColor', '#15BE7C')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('viewTicketThreadStarterTextColor', '#ffffff')); ?>;
                    padding: <?php echo esc_attr($attributes['viewTicketThreadStarterPaddingTop']); ?>px <?php echo esc_attr($attributes['viewTicketThreadStarterPaddingRight']); ?>px <?php echo esc_attr($attributes['viewTicketThreadStarterPaddingBottom']); ?>px <?php echo esc_attr($attributes['viewTicketThreadStarterPaddingLeft']); ?>px;
                }

                .fs_cc_customer {
                    background-color: <?php echo esc_attr(self::getPrintableColor('viewTicketThreadFollowerBgColor', '#ff00ff')); ?>;
                    color: <?php echo esc_attr(self::getPrintableColor('viewTicketThreadFollowerTextColor', '#ffffff')); ?>;
                    border-left: <?php echo esc_attr($attributes['viewTicketThreadFollowerTailWidth']); ?>px solid <?php echo esc_attr($attributes['viewTicketThreadFollowerBgColor']); ?>;;
                }

                .fs_thread_ribbon_customer_cc {
                    background-color: <?php echo esc_attr(self::getPrintableColor('viewTicketThreadFollowerBgColor', '#ff00ff')); ?>;
                    padding: <?php echo esc_attr($attributes['viewTicketThreadFollowerPaddingTop']); ?>px <?php echo esc_attr($attributes['viewTicketThreadFollowerPaddingRight']); ?>px <?php echo esc_attr($attributes['viewTicketThreadFollowerPaddingBottom']); ?>px <?php echo esc_attr($attributes['viewTicketThreadFollowerPaddingLeft']); ?>px;
                    color: <?php echo esc_attr(self::getPrintableColor('viewTicketThreadFollowerTextColor', '#ffffff')); ?>;
                }
            }
        </style>
        <?php
    }

    /**
     * Decide whether a color attribute is printable. If not, return the default color.
     *
     * This function checks if the provided color attribute is empty or null. If it is, it returns the default color.
     * Otherwise, it returns the color attribute.
     *
     * @param string|null $colorAttribute The color attribute to check.
     * @param string $defaultColor The default color to use if the attribute is not printable.
     * @return string The printable color or the default color.
     */
    public static function getPrintableColor($colorAttribute, $defaultColor) {
        if( !self::isThisItemPrintAble($colorAttribute)){
            return $defaultColor;
        }
        return esc_attr(self::$attributes[$colorAttribute]);
    }

    /**
     * Check if a attribute is printable.
     *
     * This method checks whether a specific attribute has a value that should be included in
     * the generated CSS.
     *
     * @param string $attribute The name of the border-related attribute to check.
     * @return bool True if the attribute is printable, false otherwise.
     */
    public static function isThisItemPrintAble($attribute)
    {
        return isset(self::$attributes[$attribute]) && (self::$attributes[$attribute] != '' && self::$attributes[$attribute] != 0);
    }

    /**
     * Generate CSS for border properties (top, right, bottom, left).
     *
     * This method generates CSS for border properties based on the provided attribute values, including
     * border width and border color for each side (top, right, bottom, left).
     *
     * @param string $parentName The base name of the border-related attributes.
     * @return string The generated CSS for the border properties.
     */
    public static function getBorderStyle($parentName)
    {
        $borderStyle = '';
        $BorderTop = $parentName . 'WidthTop';
        $borderColor = self::getPrintableColor($parentName . 'Color', '#ffffff');
        if( self::isThisItemPrintAble($BorderTop) ) {
            $borderStyle .= 'border-top: ' . esc_attr(self::$attributes[$BorderTop]) . 'px solid ' . $borderColor . ';';
        }
        $borderRight = $parentName . 'WidthRight';
        if( self::isThisItemPrintAble($borderRight) ) {
            $borderStyle .= 'border-right: ' . esc_attr(self::$attributes[$borderRight]) . 'px solid ' . $borderColor . ';';
        }
        $borderBottom = $parentName . 'WidthBottom';
        if( self::isThisItemPrintAble($borderRight) ) {
            $borderStyle .= 'border-bottom: ' . esc_attr(self::$attributes[$borderBottom]) . 'px solid ' . $borderColor . ';';
        }
        $borderLeft = $parentName . 'WidthLeft';
        if( self::isThisItemPrintAble($borderLeft) ) {
            $borderStyle .= 'border-left: ' . esc_attr(self::$attributes[$borderLeft]) . 'px solid ' . $borderColor . ';';
        }
        return $borderStyle;
    }

    /**
     * Generate CSS for border radius properties (top-left, top-right, bottom-right, bottom-left).
     *
     * This method generates CSS for border radius properties based on the provided attribute values, including
     * radius values for each corner (top-left, top-right, bottom-right, bottom-left).
     *
     * @param string $parentName The base name of the border radius-related attributes.
     * @return string The generated CSS for the border radius properties.
     */
    public static function getBorderRadiusStyle($parentName){
        $borderRadius = '';
        $topLeft = $parentName . 'RadiusTopLeft';
        if( self::isThisItemPrintAble($topLeft) ) {
            $borderRadius .= 'border-top-left-radius: ' . self::$attributes[$topLeft] . 'px;';
        }
        $topRight = $parentName . 'RadiusTopRight';
        if( self::isThisItemPrintAble($topRight) ) {
            $borderRadius .= 'border-top-right-radius: ' . self::$attributes[$topRight] . 'px;';
        }
        $bottomRight = $parentName . 'RadiusBottomRight';
        if( self::isThisItemPrintAble($bottomRight) ) {
            $borderRadius .= 'border-bottom-right-radius: ' . self::$attributes[$bottomRight] . 'px;';
        }
        $bottomLeft = $parentName . 'RadiusBottomLeft';
        if( self::isThisItemPrintAble($bottomLeft) ) {
            $borderRadius .= 'border-bottom-left-radius: ' . self::$attributes[$bottomLeft] . 'px;';
        }
        return $borderRadius;
    }
}
