// exit: 42
// stdout: Point(10, 20)
// stdout: Distance: 30

struct Point {
    x: i32,
    y: i32,
}

impl Point {
    fn new(x: i32, y: i32) -> Point {
        Point { x: x, y: y }
    }

    fn sum(&self) -> i32 {
        self.x + self.y
    }

    fn set_x(&mut self, new_x: i32) {
        self.x = new_x;
    }

    fn print(&self) {
        println!("Point({}, {})", self.x, self.y);
    }
}

fn main() {
    let mut p = Point::new(10, 20);
    p.print();
    println!("Distance: {}", p.sum());
    
    p.set_x(22);
    exit(p.sum());
}
