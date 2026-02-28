// exit: 35

struct Point {
    x: i32,
    y: i32,
}

trait HasX {
    fn get_x(&self) -> i32;
}

trait HasY {
    fn get_y(&self) -> i32;
}

impl HasX for Point {
    fn get_x(&self) -> i32 {
        self.x
    }
}

impl HasY for Point {
    fn get_y(&self) -> i32 {
        self.y
    }
}

fn sum_xy<T: HasX + HasY>(val: &T) -> i32 {
    val.get_x() + val.get_y()
}

fn main() {
    let p = Point { x: 15, y: 20 };
    exit(sum_xy(&p));
}
