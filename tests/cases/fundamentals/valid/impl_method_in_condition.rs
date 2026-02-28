// exit: 1
// stdout: true

struct Checker {
    threshold: i32,
    count: i32,
}

impl Checker {
    fn new(threshold: i32) -> Checker {
        Checker { threshold: threshold, count: 0 }
    }

    fn check(&self, val: i32) -> bool {
        val > self.threshold
    }

    fn bump(&mut self) {
        self.count = self.count + 1;
    }

    fn get_count(&self) -> i32 {
        self.count
    }
}

fn main() {
    let mut c = Checker::new(10);
    if c.check(15) {
        println!("true");
        c.bump();
    }
    exit(c.get_count());
}
