<aside id="<?php echo $args['widget_id']; ?>" class="widget widget_spam_attack_preventions masonry-brick">
	<h3 class="widget-title">
		<a href="http://wordpress.org/plugins/spammers-suck/" target="_blank">
			<?php printf(__('Spam Attacks: %1$s', 'spammers-suck'), number_format($statistics['total'])); ?>
		</a>
	</h3>
	<ul>
		<li><?php printf(__('Year: %1$s', 'spammers-suck'), number_format($statistics['year'])); ?></li>
		<li><?php printf(__('Month: %1$s', 'spammers-suck'), number_format($statistics['month'])); ?></li>
		<li><?php printf(__('Day: %1$s', 'spammers-suck'), number_format($statistics['day'])); ?></li>
	</ul>
</aside>